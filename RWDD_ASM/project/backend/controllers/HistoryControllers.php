<?php
// =================================
// * Filename : HistoryControllers.php
// * Date : 23/11/2026
// * Author : Ivan Yong
// * Description : For create, get, update and delete challenge history
// =================================

require_once __DIR__ . '/../model/ChallengeHistory.php';

class HistoryControllers
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    // Create (challenges_id is the original challenge's id when archiving)
    public function createHistory(ChallengeHistory $history)
    {
        $challenges_id = $history->getChallengesId();
        $title         = $history->getTitle();
        $image         = $history->getImage();
        $description   = $history->getDescription();
        $category      = $history->getCategory();
        $points_reward = $history->getPointsReward();
        $points_cost   = $history->getPointsCost();
        $xp_reward     = $history->getXpReward();
        $deadline      = $history->getDeadline();
        $location      = $history->getLocation();
        $created_by    = $history->getCreatedBy();
        $is_active     = (int) $history->getIsActive();

        $stmt = mysqli_prepare($this->conn, "INSERT INTO challenge_history(challenges_id, title, image, description, category, points_reward, points_cost, xp_reward, deadline, location, created_by, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        mysqli_stmt_bind_param($stmt, "issssiiissii", $challenges_id, $title, $image, $description, $category, $points_reward, $points_cost, $xp_reward, $deadline, $location, $created_by, $is_active);

        if (!mysqli_stmt_execute($stmt)) {
            return false;
        }

        // Returns 0 when the table is not auto-increment and the id was supplied
        return $challenges_id ?? mysqli_insert_id($this->conn);
    }

    public function getAllHistory()
    {
        $result = mysqli_query($this->conn, "SELECT challenges_id, title, image, description, category, points_reward, points_cost, xp_reward, deadline, location, created_by, is_active FROM challenge_history");
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    public function getHistoryId(int $challenges_id)
    {
        $stmt = mysqli_prepare($this->conn, "SELECT challenges_id, title, image, description, category, points_reward, points_cost, xp_reward, deadline, location, created_by, is_active FROM challenge_history WHERE challenges_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $challenges_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        return mysqli_fetch_assoc($result);
    }

    public function updateHistory(int $challenges_id, ChallengeHistory $history)
    {
        $title         = $history->getTitle();
        $image         = $history->getImage();
        $description   = $history->getDescription();
        $category      = $history->getCategory();
        $points_reward = $history->getPointsReward();
        $points_cost   = $history->getPointsCost();
        $xp_reward     = $history->getXpReward();
        $deadline      = $history->getDeadline();
        $location      = $history->getLocation();
        $created_by    = $history->getCreatedBy();
        $is_active     = (int) $history->getIsActive();

        $stmt = mysqli_prepare(
            $this->conn,
            "UPDATE challenge_history
            SET title = ?, image = ?, description = ?, category = ?,
                points_reward = ?, points_cost = ?, xp_reward = ?,
                deadline = ?, location = ?, created_by = ?, is_active = ?
          WHERE challenges_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "ssssiiissiii", $title, $image, $description, $category, $points_reward, $points_cost, $xp_reward, $deadline, $location, $created_by, $is_active, $challenges_id);

        return mysqli_stmt_execute($stmt);
    }

    public function deleteHistory(int $challenges_id)
    {
        $stmt = mysqli_prepare($this->conn, "DELETE FROM challenge_history WHERE challenges_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $challenges_id);

        if (!mysqli_stmt_execute($stmt)) {
            return false;
        }

        return mysqli_stmt_affected_rows($stmt);
    }
}