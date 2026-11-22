<?php
// =================================
// * Filename : review_submission.php
// * Date : 12/11/2026
// * Author : (add your name)
// * Description : ADMIN/MARKER ONLY, POST {"submission_id": 1, "action": "approve" or "reject", "reason": "..."}
// =================================

require_once __DIR__ . "/../../config/Database.php";
require_once __DIR__ . "/../../controllers/DashboardControllers.php";
require_once __DIR__ . "/../../helpers/api.php";

$reviewerId = requireReviewerApi();
requireMethod("POST");

$input = readJson();
$action = $input["action"] ?? "";
if (!in_array($action, ["approve", "reject"], true)) {
    jsonError('Action must be "approve" or "reject"', 422);
}

$controller = new DashboardController($conn);
jsonResponse($controller->reviewSubmission((int) ($input["submission_id"] ?? 0), $reviewerId, $action, trim((string) ($input["reason"] ?? ""))));
