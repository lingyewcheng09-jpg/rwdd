<?php
// =================================
// * Filename : submit_challenge.php
// * Date : 12/11/2026
// * Author : (add your name)
// * Description : POST (form data: challenge_id, photo): the user uploads a photo as proof for a joined challenge
// =================================

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../controllers/DashboardControllers.php";
require_once __DIR__ . "/../../helpers/api.php";

require_once __DIR__ . "/../../helpers/common.php";     // saveImage()

$userId = requireUserApi();
requireMethod("POST");

$challengeId = (int) ($_POST["challenge_id"] ?? 0);
$controller = new DashboardController($conn);

// 1. Check the challenge BEFORE saving the photo (it must be joined and the time must still be running)
$controller->assertCanSubmit($userId, $challengeId);

// 2. Save the photo (folder on the computer, and the path that is saved in the database)
$imageFolder = __DIR__ . "/../../../frontend/images/submissions/";
$imagePath = "images/submissions/";

$photo = saveImage("photo", $imageFolder, $imagePath);
if ($photo === null) {
    jsonError("Please choose a photo to upload", 422);
}
if ($photo === false) {
    jsonError("The photo must be a JPG, PNG or WEBP image of 2 MB or less", 422);
}

// 3. Remember the photo in the database (status: pending)
jsonResponse($controller->createSubmission($userId, $challengeId, $photo));