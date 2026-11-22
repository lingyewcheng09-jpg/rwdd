<?php
// =================================
// * Filename : rewards.php
// * Date : 12/11/2026
// * Author : (add your name)
// * Description : GET: the rewards the user can redeem
// =================================

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../controllers/DashboardControllers.php";
require_once __DIR__ . "/../../helpers/api.php";

requireUserApi();
requireMethod("GET");

$controller = new DashboardController($conn);
jsonResponse($controller->getRewards());