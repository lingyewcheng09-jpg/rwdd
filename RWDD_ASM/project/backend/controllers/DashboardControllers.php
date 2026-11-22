<?php
// =================================
// * Filename : DashboardControllers.php
// * Date : 12/11/2026
// * Author : (add your name)
// * Description : All the database work for the user pages: profile, challenges, join, photo,
// *               history, badges, rewards, redeem and leaderboard (+ approving a photo)
// =================================

require_once __DIR__ . "/../helpers/api.php";

class DashboardController
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    /* ==========================================================================
       1. SMALL DATABASE HELPERS (every query uses "?" so user input is safe)
       ========================================================================== */

    // Many rows
    private function fetchAll(string $sql, string $types = "", array $params = []): array
    {
        $stmt = mysqli_prepare($this->conn, $sql);
        if ($types !== "") {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);
        mysqli_stmt_close($stmt);
        return $rows;
    }

    // One row (or null)
    private function fetchOne(string $sql, string $types = "", array $params = []): ?array
    {
        $rows = $this->fetchAll($sql, $types, $params);
        return $rows[0] ?? null;
    }

    // INSERT / UPDATE (returns the number of changed rows)
    private function run(string $sql, string $types = "", array $params = []): int
    {
        $stmt = mysqli_prepare($this->conn, $sql);
        if ($types !== "") {
            mysqli_stmt_bind_param($stmt, $types, ...$params);
        }
        mysqli_stmt_execute($stmt);
        $changed = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);
        return $changed;
    }

    // Runs several changes as one: all succeed or all are undone
    private function transaction(callable $work)
    {
        mysqli_begin_transaction($this->conn);
        try {
            $result = $work();
            mysqli_commit($this->conn);
            return $result;
        } catch (Throwable $error) {
            mysqli_rollback($this->conn);
            throw $error;
        }
    }

    /* ==========================================================================
       2. RANKS AND XP
       The rank of a user comes from their XP: the XP of all their APPROVED challenges.
       ========================================================================== */

    // All ranks, lowest first. level = 1, 2, 3 ... in the order of xp_required.
    public function getRanks(): array
    {
        $rows = $this->fetchAll("SELECT rank_id, rank_name, xp_required FROM `rank` ORDER BY xp_required ASC");
        $ranks = [];
        foreach ($rows as $index => $row) {
            $ranks[] = [
                "id"         => (int) $row["rank_id"],
                "level"      => $index + 1,
                "name"       => ucfirst($row["rank_name"]),
                "min_points" => (int) $row["xp_required"],
            ];
        }
        return $ranks;
    }

    public function getXp(int $userId): int
    {
        $row = $this->fetchOne(
            "SELECT COALESCE(SUM(c.xp_reward), 0) AS xp
             FROM submissions s JOIN challenges c ON c.challenge_id = s.challenges_id
             WHERE s.user_id = ? AND s.status = 'approved'",
            "i", [$userId]
        );
        return (int) $row["xp"];
    }

    // The highest rank reached with this much XP
    private function findRank(array $ranks, int $xp): array
    {
        $current = $ranks[0];
        foreach ($ranks as $rank) {
            if ($xp >= $rank["min_points"]) {
                $current = $rank;
            }
        }
        return $current;
    }

    // The rank after the current one (null at the top)
    private function findNextRank(array $ranks, array $current): ?array
    {
        foreach ($ranks as $rank) {
            if ($rank["min_points"] > $current["min_points"]) {
                return $rank;
            }
        }
        return null;
    }

    // Keeps users.rank_id in step with the XP (other pages read this column)
    private function syncRank(int $userId)
    {
        $rank = $this->findRank($this->getRanks(), $this->getXp($userId));
        $this->run("UPDATE users SET rank_id = ? WHERE user_id = ?", "ii", [$rank["id"], $userId]);
    }

    /* ==========================================================================
       3. PROFILE (name, picture, points, rank: used by the banner of every page)
       ========================================================================== */

    public function getProfile(int $userId): array
    {
        $user = $this->fetchOne("SELECT username, profile_picture, points_balance FROM users WHERE user_id = ?", "i", [$userId]);
        if (!$user) {
            throw new ApiException("User not found", 404);
        }

        $xp = $this->getXp($userId);
        $ranks = $this->getRanks();
        $rank = $this->findRank($ranks, $xp);

        $spent = $this->fetchOne("SELECT COALESCE(SUM(points_spend), 0) AS total FROM redemptions WHERE user_id = ?", "i", [$userId]);
        $counts = $this->fetchOne(
            "SELECT
                (SELECT COUNT(*) FROM submissions WHERE user_id = ? AND status = 'approved') AS approved,
                (SELECT COUNT(*) FROM submissions WHERE user_id = ? AND status = 'pending')  AS pending,
                (SELECT COUNT(*) FROM user_achievement WHERE user_id = ?)                    AS badges,
                (SELECT COUNT(*) FROM achievement)                                           AS badge_total",
            "iii", [$userId, $userId, $userId]
        );

        return [
            "user"      => ["name" => $user["username"], "picture" => $user["profile_picture"]],
            "points"    => (int) $user["points_balance"],       // points the user can spend
            "lifetime"  => $xp,                                  // total XP: this decides the rank
            "spent"     => (int) $spent["total"],
            "rank"      => $rank,
            "next_rank" => $this->findNextRank($ranks, $rank),
            "ranks"     => $ranks,
            "stats"     => [
                "approved"    => (int) $counts["approved"],
                "pending"     => (int) $counts["pending"],
                "badges"      => (int) $counts["badges"],
                "badge_total" => (int) $counts["badge_total"],
            ],
        ];
    }

    /* ==========================================================================
       4. CHALLENGES: the list, joining, and the photo
       status of a challenge for ONE user:
         open (never joined) | joined (time is running) | pending | approved | rejected
       ========================================================================== */

    // The newest join and the newest photo of one user for one challenge
    private function latestAttempt(int $userId, int $challengeId): array
    {
        $join = $this->fetchOne(
            "SELECT joined_at, deadline_at, deadline_at > NOW() AS still_open
             FROM challenge_joins WHERE user_id = ? AND challenge_id = ? ORDER BY join_id DESC LIMIT 1",
            "ii", [$userId, $challengeId]
        );
        $photo = $this->fetchOne(
            "SELECT status, rejection_reason, submitted_at
             FROM submissions WHERE user_id = ? AND challenges_id = ? ORDER BY submission_id DESC LIMIT 1",
            "ii", [$userId, $challengeId]
        );
        return $this->statusOf($join, $photo);
    }

    // Works out the status from the newest join and the newest photo
    private function statusOf(?array $join, ?array $photo): array
    {
        // A photo sent after joining decides the status (pending, approved or rejected)
        if ($photo && (!$join || $photo["submitted_at"] >= $join["joined_at"])) {
            return ["status" => $photo["status"], "deadline" => $join["deadline_at"] ?? null,
                    "note" => $photo["rejection_reason"] ?? "", "still_open" => false];
        }
        if ($join) {
            return ["status" => "joined", "deadline" => $join["deadline_at"], "note" => "", "still_open" => (bool) $join["still_open"]];
        }
        return ["status" => "open", "deadline" => null, "note" => "", "still_open" => false];
    }

    public function getChallenges(int $userId): array
    {
        // Challenges that have not ended yet, plus the ones this user has already tried
        $challenges = $this->fetchAll(
            "SELECT challenge_id, title, description, category, points_reward, xp_reward, time_limit_hours, image
             FROM challenges
             WHERE deadline > NOW() OR challenge_id IN (SELECT challenges_id FROM submissions WHERE user_id = ?)
             ORDER BY challenge_id DESC",
            "i", [$userId]
        );

        $joins = [];
        foreach ($this->fetchAll(
            "SELECT j.challenge_id, j.joined_at, j.deadline_at, j.deadline_at > NOW() AS still_open
             FROM challenge_joins j
             JOIN (SELECT challenge_id, MAX(join_id) AS newest FROM challenge_joins WHERE user_id = ? GROUP BY challenge_id) m
               ON m.newest = j.join_id",
            "i", [$userId]
        ) as $row) {
            $joins[$row["challenge_id"]] = $row;
        }

        $photos = [];
        foreach ($this->fetchAll(
            "SELECT s.challenges_id, s.status, s.rejection_reason, s.submitted_at
             FROM submissions s
             JOIN (SELECT challenges_id, MAX(submission_id) AS newest FROM submissions WHERE user_id = ? GROUP BY challenges_id) m
               ON m.newest = s.submission_id",
            "i", [$userId]
        ) as $row) {
            $photos[$row["challenges_id"]] = $row;
        }

        $list = [];
        foreach ($challenges as $c) {
            $id = $c["challenge_id"];
            $attempt = $this->statusOf($joins[$id] ?? null, $photos[$id] ?? null);

            $list[] = [
                "id"               => (int) $id,
                "title"            => $c["title"],
                "description"      => $c["description"],
                "difficulty"       => $c["category"],                 // beginner, intermediate or advanced
                "points"           => (int) $c["points_reward"],
                "xp"               => (int) $c["xp_reward"],
                "image"            => $c["image"],
                "time_limit_hours" => (int) $c["time_limit_hours"],
                "status"           => $attempt["status"],
                "deadline"         => $attempt["deadline"],           // the user must upload the photo before this
                "note"             => $attempt["note"],               // the reason when the photo was rejected
            ];
        }
        return $list;
    }

    // The user presses "Join": the time limit starts now
    public function joinChallenge(int $userId, int $challengeId): array
    {
        $challenge = $this->fetchOne("SELECT title, deadline > NOW() AS still_open FROM challenges WHERE challenge_id = ?", "i", [$challengeId]);
        if (!$challenge) {
            throw new ApiException("Challenge not found", 404);
        }
        if (!$challenge["still_open"]) {
            throw new ApiException("This challenge has ended", 409);
        }

        $attempt = $this->latestAttempt($userId, $challengeId);
        if ($attempt["status"] === "pending") {
            throw new ApiException("Your photo is still waiting for review", 409);
        }
        if ($attempt["status"] === "joined" && $attempt["still_open"]) {
            throw new ApiException("You already joined this challenge", 409);
        }

        // The time is the challenge's time limit, but never later than the challenge's own deadline
        $this->run(
            "INSERT INTO challenge_joins (user_id, challenge_id, deadline_at)
             SELECT ?, challenge_id, LEAST(NOW() + INTERVAL time_limit_hours HOUR, deadline)
             FROM challenges WHERE challenge_id = ?",
            "ii", [$userId, $challengeId]
        );

        $join = $this->fetchOne("SELECT deadline_at FROM challenge_joins WHERE join_id = ?", "i", [mysqli_insert_id($this->conn)]);
        return ["message" => "You joined " . $challenge["title"] . ". Upload your photo before the time is up.", "deadline" => $join["deadline_at"]];
    }

    // Checked BEFORE the photo is saved: only a joined challenge whose time is still running accepts a photo
    public function assertCanSubmit(int $userId, int $challengeId)
    {
        $attempt = $this->latestAttempt($userId, $challengeId);

        if ($attempt["status"] === "pending") {
            throw new ApiException("Your photo is still waiting for review", 409);
        }
        if ($attempt["status"] !== "joined") {
            throw new ApiException("Join the challenge first", 409);
        }
        if (!$attempt["still_open"]) {
            throw new ApiException("The time is up. Please join the challenge again.", 409);
        }
    }

    public function createSubmission(int $userId, int $challengeId, string $photoPath): array
    {
        $this->run("INSERT INTO submissions (user_id, challenges_id, photo_path) VALUES (?, ?, ?)", "iis", [$userId, $challengeId, $photoPath]);
        return ["message" => "Photo uploaded. An admin will review it soon."];
    }

    /* ==========================================================================
       5. HISTORY (points earned, points spent, photos waiting or rejected)
       ========================================================================== */

    public function getHistory(int $userId): array
    {
        $rows = $this->fetchAll(
            "(SELECT 'earn' AS type, c.title AS title, c.points_reward AS points,
                     COALESCE(s.verified_at, s.submitted_at) AS date, '' AS note
              FROM submissions s JOIN challenges c ON c.challenge_id = s.challenges_id
              WHERE s.user_id = ? AND s.status = 'approved')
             UNION ALL
             (SELECT s.status, c.title, c.points_reward, s.submitted_at, COALESCE(s.rejection_reason, '')
              FROM submissions s JOIN challenges c ON c.challenge_id = s.challenges_id
              WHERE s.user_id = ? AND s.status IN ('pending', 'rejected'))
             UNION ALL
             (SELECT 'spend', CONCAT('Redeemed: ', r.reward_item), d.points_spend, d.redeemed_at, ''
              FROM redemptions d JOIN rewards r ON r.reward_id = d.reward_id
              WHERE d.user_id = ?)
             ORDER BY date DESC",
            "iii", [$userId, $userId, $userId]
        );

        foreach ($rows as &$row) {
            $row["points"] = (int) $row["points"];
        }
        return $rows;
    }

    /* ==========================================================================
       6. BADGES (a badge is unlocked when the user has enough approved challenges)
       ========================================================================== */

    public function getBadges(int $userId): array
    {
        $rows = $this->fetchAll(
            "SELECT a.achievement_id, a.achievement_name, a.achievement_description, a.achievement_image,
                    (ua.user_id IS NOT NULL) AS earned
             FROM achievement a
             LEFT JOIN user_achievement ua ON ua.achievement_id = a.achievement_id AND ua.user_id = ?
             ORDER BY a.requirement_value ASC",
            "i", [$userId]
        );

        $badges = [];
        foreach ($rows as $row) {
            $badges[] = [
                "id"          => (int) $row["achievement_id"],
                "name"        => $row["achievement_name"],
                "description" => $row["achievement_description"],
                "image"       => $row["achievement_image"],
                "earned"      => (bool) $row["earned"],
            ];
        }
        return $badges;
    }

    // Unlocks every badge the user now deserves. Returns the names of the NEW badges.
    private function awardBadges(int $userId): array
    {
        $approved = $this->fetchOne("SELECT COUNT(*) AS total FROM submissions WHERE user_id = ? AND status = 'approved'", "i", [$userId]);
        $notOwned = $this->fetchAll(
            "SELECT achievement_id, achievement_name FROM achievement
             WHERE requirement_value <= ?
               AND achievement_id NOT IN (SELECT achievement_id FROM user_achievement WHERE user_id = ?)",
            "ii", [(int) $approved["total"], $userId]
        );

        $names = [];
        foreach ($notOwned as $badge) {
            $this->run("INSERT INTO user_achievement (user_id, achievement_id, unlocked_at) VALUES (?, ?, UNIX_TIMESTAMP())", "ii", [$userId, $badge["achievement_id"]]);
            $names[] = $badge["achievement_name"];
        }
        return $names;
    }

    /* ==========================================================================
       7. REWARDS: the list and redeeming
       ========================================================================== */

    public function getRewards(): array
    {
        $ranks = $this->getRanks();
        $levelOf = [];
        foreach ($ranks as $rank) {
            $levelOf[$rank["id"]] = $rank["level"];
        }

        $list = [];
        foreach ($this->fetchAll("SELECT reward_id, reward_item, description, points_cost, stock, min_rank_id, image FROM rewards ORDER BY points_cost ASC") as $row) {
            $list[] = [
                "id"          => (int) $row["reward_id"],
                "name"        => $row["reward_item"],
                "description" => $row["description"],
                "cost"        => (int) $row["points_cost"],
                "stock"       => (int) $row["stock"],
                "min_level"   => $levelOf[(int) $row["min_rank_id"]] ?? 1,
                "image"       => $row["image"],
            ];
        }
        return $list;
    }

    // Spends points on a reward. Rank, stock and balance are all checked here.
    public function redeemReward(int $userId, int $rewardId): array
    {
        return $this->transaction(function () use ($userId, $rewardId) {

            // "FOR UPDATE" locks the rows, so two quick clicks cannot spend the same points twice
            $user = $this->fetchOne("SELECT points_balance FROM users WHERE user_id = ? FOR UPDATE", "i", [$userId]);
            $reward = $this->fetchOne("SELECT reward_item, points_cost, stock, min_rank_id FROM rewards WHERE reward_id = ? FOR UPDATE", "i", [$rewardId]);

            if (!$user || !$reward) {
                throw new ApiException("Reward not found", 404);
            }
            if ((int) $reward["stock"] <= 0) {
                throw new ApiException("This reward is out of stock", 409);
            }

            $ranks = $this->getRanks();
            $userRank = $this->findRank($ranks, $this->getXp($userId));
            foreach ($ranks as $rank) {
                if ($rank["id"] === (int) $reward["min_rank_id"] && $userRank["level"] < $rank["level"]) {
                    throw new ApiException("Your rank is too low for this reward", 403);
                }
            }

            $cost = (int) $reward["points_cost"];
            if ((int) $user["points_balance"] < $cost) {
                throw new ApiException("Not enough points", 422);
            }

            $this->run("INSERT INTO redemptions (user_id, reward_id, points_spend) VALUES (?, ?, ?)", "iii", [$userId, $rewardId, $cost]);
            $this->run("UPDATE users SET points_balance = points_balance - ? WHERE user_id = ?", "ii", [$cost, $userId]);
            $this->run("UPDATE rewards SET stock = stock - 1 WHERE reward_id = ?", "i", [$rewardId]);

            return ["message" => $reward["reward_item"] . " redeemed", "new_badges" => []];
        });
    }

    /* ==========================================================================
       8. LEADERBOARD (top 20 users by total XP, plus the logged-in user's own position)
       ========================================================================== */

    public function getLeaderboard(int $userId, int $limit = 20): array
    {
        $ranks = $this->getRanks();
        $rows = $this->fetchAll(
            "SELECT u.user_id, u.username, u.profile_picture, COALESCE(SUM(c.xp_reward), 0) AS xp
             FROM users u
             LEFT JOIN submissions s ON s.user_id = u.user_id AND s.status = 'approved'
             LEFT JOIN challenges c ON c.challenge_id = s.challenges_id
             WHERE u.role = 'user'
             GROUP BY u.user_id, u.username, u.profile_picture
             ORDER BY xp DESC, u.user_id ASC"
        );

        $people = [];
        $me = null;
        foreach ($rows as $index => $row) {
            $rank = $this->findRank($ranks, (int) $row["xp"]);
            $person = [
                "position"   => $index + 1,
                "name"       => $row["username"],
                "picture"    => $row["profile_picture"],
                "points"     => (int) $row["xp"],
                "rank_name"  => $rank["name"],
                "rank_level" => $rank["level"],
                "is_me"      => (int) $row["user_id"] === $userId,
            ];
            $people[] = $person;
            if ($person["is_me"]) {
                $me = $person;                      // the real position, even outside the top 20
            }
        }
        return ["leaderboard" => array_slice($people, 0, $limit), "me" => $me];
    }

    /* ==========================================================================
       9. ADMIN SIDE: approve or reject a photo
       Approving gives the user the challenge points, updates the rank and unlocks badges.
       ========================================================================== */

    public function reviewSubmission(int $submissionId, int $reviewerId, string $action, string $reason): array
    {
        return $this->transaction(function () use ($submissionId, $reviewerId, $action, $reason) {

            $photo = $this->fetchOne(
                "SELECT s.user_id, s.status, c.points_reward
                 FROM submissions s JOIN challenges c ON c.challenge_id = s.challenges_id
                 WHERE s.submission_id = ? FOR UPDATE",
                "i", [$submissionId]
            );
            if (!$photo) {
                throw new ApiException("Submission not found", 404);
            }
            if ($photo["status"] !== "pending") {
                throw new ApiException("This photo was already reviewed", 409);
            }

            $status = $action === "approve" ? "approved" : "rejected";
            $this->run(
                "UPDATE submissions SET status = ?, rejection_reason = ?, verified_by = ?, verified_at = NOW() WHERE submission_id = ?",
                "ssii", [$status, $reason !== "" ? $reason : null, $reviewerId, $submissionId]
            );

            $newBadges = [];
            if ($action === "approve") {
                $userId = (int) $photo["user_id"];
                $this->run("UPDATE users SET points_balance = points_balance + ? WHERE user_id = ?", "ii", [(int) $photo["points_reward"], $userId]);
                $this->syncRank($userId);
                $newBadges = $this->awardBadges($userId);
            }
            return ["status" => $status, "new_badges" => $newBadges];
        });
    }
}
