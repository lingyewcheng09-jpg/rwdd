<?php
// =================================
// * Filename : profile.php
// * Date : 12/11/2026
// * Author : (add your name)
// * Description : GET: the user's name, picture, points, rank and numbers (every page calls this first)
// =================================

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../controllers/DashboardControllers.php";
require_once __DIR__ . "/../../helpers/api.php";

$userId = requireUserApi();            // 1. who is logged in? (401 if nobody)
requireMethod("GET");                  // 2. only GET is allowed

$controller = new DashboardController($conn);
jsonResponse($controller->getProfile($userId));    // 3. ask the controller, 4. answer with JSON