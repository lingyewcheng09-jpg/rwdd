<?php
// =================================
// * Filename : LeaderboardControllers.php
// * Date : 23/11/2026
// * Author : Ivan Yong
// * Description : For create, get, update and delete leaderboard entries
// =================================

require_once __DIR__ . '/../model/Leaderboard.php';

class LeaderboardControllers
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    // Create
    public function createLeaderboard(Leaderboard $leaderboard)
    {
        $season_id = $leaderboard->getSeasonId();
        $user_id   = $leaderboard->getUserId();
        $rank_id   = $leaderboard->getRankId();

        $stmt = mysqli_prepare($this->conn, "INSERT INTO leaderboard(season_id, user_id, rank_id) VALUES (?,?,?)");
        mysqli_stmt_bind_param($stmt, "iii", $season_id, $user_id, $rank_id);

        // No auto-increment id on this table, so return true/false
        return mysqli_stmt_execute($stmt);
    }

    public function getAllLeaderboard()
    {
        $result = mysqli_query($this->conn, "SELECT season_id, user_id, rank_id FROM leaderboard");
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    public function getLeaderboardBySeasonId(int $season_id)
    {
        $stmt = mysqli_prepare($this->conn, "SELECT season_id, user_id, rank_id FROM leaderboard WHERE season_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $season_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    public function getLeaderboardByUserId(int $user_id)
    {
        $stmt = mysqli_prepare($this->conn, "SELECT season_id, user_id, rank_id FROM leaderboard WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    public function getLeaderboardId(int $season_id, int $user_id)
    {
        $stmt = mysqli_prepare($this->conn, "SELECT season_id, user_id, rank_id FROM leaderboard WHERE season_id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $season_id, $user_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        return mysqli_fetch_assoc($result);
    }

    public function updateLeaderboard(int $season_id, int $user_id, Leaderboard $leaderboard)
    {
        $rank_id = $leaderboard->getRankId();

        $stmt = mysqli_prepare(
            $this->conn,
            "UPDATE leaderboard
            SET rank_id = ?
          WHERE season_id = ? AND user_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "iii", $rank_id, $season_id, $user_id);

        return mysqli_stmt_execute($stmt);
    }

    public function deleteLeaderboard(int $season_id, int $user_id)
    {
        $stmt = mysqli_prepare($this->conn, "DELETE FROM leaderboard WHERE season_id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, "ii", $season_id, $user_id);

        if (!mysqli_stmt_execute($stmt)) {
            return false;
        }

        return mysqli_stmt_affected_rows($stmt);
    }
}