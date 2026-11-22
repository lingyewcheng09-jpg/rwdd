<?php
// =================================
// * Filename : challenges.php
// * Date : 12/11/2026
// * Author : (add your name)
// * Description : GET: the challenges and the status of each one for the logged-in user
// =================================

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../controllers/DashboardControllers.php";
require_once __DIR__ . "/../../helpers/api.php";

$userId = requireUserApi();
requireMethod("GET");

$controller = new DashboardController($conn);
jsonResponse($controller->getChallenges($userId));