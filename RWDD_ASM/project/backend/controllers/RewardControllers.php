<?php
// =================================
// * Filename : RewardControllers.php
// * Date : 23/11/2026
// * Author : Ivan Yong
// * Description : For create, get, update and delete rewards
// =================================

require_once __DIR__ . '/../model/Rewards.php';

class RewardControllers
{
    private $conn;

    public function __construct($conn)
    {
        $this->conn = $conn;
    }

    // Create
    public function createReward(Rewards $reward)
    {
        $name        = $reward->getName();
        $description = $reward->getDescription();
        $points_cost = $reward->getPointsCost();
        $stock       = $reward->getStock();
        $image       = $reward->getImage();

        $stmt = mysqli_prepare($this->conn, "INSERT INTO rewards(name, description, points_cost, stock, image) VALUES (?,?,?,?,?)");
        mysqli_stmt_bind_param($stmt, "ssiis", $name, $description, $points_cost, $stock, $image);

        if (!mysqli_stmt_execute($stmt)) {
            return false;
        }

        return mysqli_insert_id($this->conn);
    }

    public function getAllReward()
    {
        $result = mysqli_query($this->conn, "SELECT reward_id, name, description, points_cost, stock, image FROM rewards");
        return mysqli_fetch_all($result, MYSQLI_ASSOC);
    }

    public function getRewardId(int $reward_id)
    {
        $stmt = mysqli_prepare($this->conn, "SELECT reward_id, name, description, points_cost, stock, image FROM rewards WHERE reward_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $reward_id);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        return mysqli_fetch_assoc($result);
    }

    public function updateReward(int $reward_id, Rewards $reward)
    {
        $name        = $reward->getName();
        $description = $reward->getDescription();
        $points_cost = $reward->getPointsCost();
        $stock       = $reward->getStock();
        $image       = $reward->getImage();

        $stmt = mysqli_prepare(
            $this->conn,
            "UPDATE rewards
            SET name = ?, description = ?, points_cost = ?,
                stock = ?, image = ?
          WHERE reward_id = ?"
        );
        mysqli_stmt_bind_param($stmt, "ssiisi", $name, $description, $points_cost, $stock, $image, $reward_id);

        return mysqli_stmt_execute($stmt);
    }

    public function deleteReward(int $reward_id)
    {
        $stmt = mysqli_prepare($this->conn, "DELETE FROM rewards WHERE reward_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $reward_id);

        if (!mysqli_stmt_execute($stmt)) {
            return false;
        }

        return mysqli_stmt_affected_rows($stmt);
    }
}