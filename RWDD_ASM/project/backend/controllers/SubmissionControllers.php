<?php
// =================================
// * Filename : SubmissionControllers.php
// * Date : 05/11/2026
// * Author : (add your name)
// * Description : Photos a user sends for a challenge. The user side creates them (status pending);
// *               approveSubmission / rejectSubmission are for the admin review page.
// =================================

require_once __DIR__ . "/../model/Submission.php";
require_once __DIR__ . "/../validation/DataValidation.php";
require_once __DIR__ . "/RankControllers.php";
require_once __DIR__ . "/BadgeControllers.php";
require_once __DIR__ . "/../config/Response.php";

class SubmissionController
{
    private $conn;

    private const TYPES = ["image/jpeg" => "jpg", "image/png" => "png", "image/webp" => "webp"];

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    // Checks the uploaded file and saves it. Returns the path to store in the database.
    public function saveUploadedPhoto(?array $file): string
    {
        $error = validatePhoto($file);
        if ($error !== null) {
            throw new UserFacingException($error, 400);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file["tmp_name"]);

        $folder = __DIR__ . "/../uploads/submissions";
        if (!is_dir($folder) && !mkdir($folder, 0775, true)) {
            throw new RuntimeException("Cannot create upload folder");
        }

        $name = bin2hex(random_bytes(16)) . "." . self::TYPES[$mime];
        if (!move_uploaded_file($file["tmp_name"], $folder . "/" . $name)) {
            throw new RuntimeException("Cannot save uploaded file");
        }
        return "uploads/submissions/" . $name;
    }

    // Saves a new pending submission. Throws a UserFacingException if the user may not send one.
    public function createSubmission(Submission $submission): int
    {
        $userId = $submission->getUserId();
        $challengeId = $submission->getChallengeId();
        $photoPath = $submission->getPhotoPath();

        mysqli_begin_transaction($this->conn);

        try {
            // Lock the user row so a double click cannot make two submissions
            $stmt = mysqli_prepare($this->conn, "SELECT id FROM users WHERE id = ? FOR UPDATE");
            mysqli_stmt_bind_param($stmt, "i", $userId);
            mysqli_stmt_execute($stmt);
            if (!mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
                throw new UserFacingException("Please log in first", 401);
            }

            $stmt = mysqli_prepare($this->conn, "SELECT deadline FROM challenge WHERE challenge_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $challengeId);
            mysqli_stmt_execute($stmt);
            $challenge = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if (!$challenge) {
                throw new UserFacingException("This challenge does not exist", 404);
            }
            if ($challenge["deadline"] !== null && strtotime($challenge["deadline"]) < time()) {
                throw new UserFacingException("The deadline of this challenge has passed", 400);
            }

            $stmt = mysqli_prepare($this->conn, "SELECT status FROM submission WHERE user_id = ? AND challenge_id = ? AND status IN ('pending', 'approved') LIMIT 1");
            mysqli_stmt_bind_param($stmt, "ii", $userId, $challengeId);
            mysqli_stmt_execute($stmt);
            $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if ($existing) {
                throw new UserFacingException(
                    $existing["status"] === "pending" ? "Your photo is still waiting for review" : "You already completed this challenge",
                    409
                );
            }

            $stmt = mysqli_prepare($this->conn, "INSERT INTO submission (user_id, challenge_id, photo_path) VALUES (?, ?, ?)");
            mysqli_stmt_bind_param($stmt, "iis", $userId, $challengeId, $photoPath);
            mysqli_stmt_execute($stmt);
            $newId = mysqli_insert_id($this->conn);

            mysqli_commit($this->conn);
            return $newId;
        } catch (Throwable $e) {
            mysqli_rollback($this->conn);
            throw $e;
        }
    }

    // For the profile page: how many photos are approved / pending
    public function countByStatus(int $userId): array
    {
        $stmt = mysqli_prepare($this->conn, "SELECT
                COALESCE(SUM(status = 'approved'), 0) AS approved,
                COALESCE(SUM(status = 'pending'), 0) AS pending
            FROM submission WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        return ["approved" => (int)$row["approved"], "pending" => (int)$row["pending"]];
    }

    // ---------- For the admin review page ----------

    // Approve: gives the points and xp, updates the rank and gives new badges.
    public function approveSubmission(int $submissionId, int $reviewerId): array
    {
        mysqli_begin_transaction($this->conn);

        try {
            $stmt = mysqli_prepare($this->conn, "SELECT s.user_id, c.points_reward, c.xp_reward
                FROM submission s JOIN challenge c ON c.challenge_id = s.challenge_id
                WHERE s.submission_id = ? AND s.status = 'pending' FOR UPDATE");
            mysqli_stmt_bind_param($stmt, "i", $submissionId);
            mysqli_stmt_execute($stmt);
            $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            if (!$row) {
                throw new UserFacingException("This submission was not found or is already reviewed", 404);
            }

            $userId = (int)$row["user_id"];
            $points = (int)$row["points_reward"];
            $xp = (int)$row["xp_reward"];

            $stmt = mysqli_prepare($this->conn, "UPDATE submission SET status = 'approved', points_awarded = ?, reviewed_by = ?, reviewed_at = NOW() WHERE submission_id = ?");
            mysqli_stmt_bind_param($stmt, "iii", $points, $reviewerId, $submissionId);
            mysqli_stmt_execute($stmt);

            $stmt = mysqli_prepare($this->conn, "UPDATE users SET points_balance = points_balance + ?, total_xp = total_xp + ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "iii", $points, $xp, $userId);
            mysqli_stmt_execute($stmt);

            mysqli_commit($this->conn);
        } catch (Throwable $e) {
            mysqli_rollback($this->conn);
            throw $e;
        }

        $stmt = mysqli_prepare($this->conn, "SELECT COALESCE(SUM(points_awarded), 0) AS lifetime FROM submission WHERE user_id = ? AND status = 'approved'");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $lifetime = (int)mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))["lifetime"];

        (new RankController($this->conn))->syncUserRank($userId, $lifetime);
        $newBadges = (new BadgeController($this->conn))->awardEligible($userId);

        return ["user_id" => $userId, "points_given" => $points, "new_badges" => $newBadges];
    }

    public function rejectSubmission(int $submissionId, int $reviewerId, string $note = ""): int
    {
        $note = preg_replace('/^(.{0,255}).*$/us', '$1', trim($note));      // max 255 characters
        $stmt = mysqli_prepare($this->conn, "UPDATE submission SET status = 'rejected', review_note = ?, reviewed_by = ?, reviewed_at = NOW()
            WHERE submission_id = ? AND status = 'pending'");
        mysqli_stmt_bind_param($stmt, "sii", $note, $reviewerId, $submissionId);
        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) === 0) {
            throw new UserFacingException("This submission was not found or is already reviewed", 404);
        }
        return $submissionId;
    }
}