<?php
// =================================
// * Filename : history.php
// * Date : 12/11/2026
// * Author : (add your name)
// * Description : GET: points earned, points spent, and photos waiting or rejected
// =================================

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../controllers/DashboardControllers.php";
require_once __DIR__ . "/../../helpers/api.php";

$userId = requireUserApi();
requireMethod("GET");

$controller = new DashboardController($conn);
jsonResponse($controller->getHistory($userId));