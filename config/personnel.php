<?php

return [
    'legacy_write_enabled' => env('LEGACY_WRITE_ENABLED', false),
    'stage_database' => env('LEGACY_STAGE_DATABASE', 'uchr_stage'),
    'local_preview' => env('PERSONNEL_LOCAL_PREVIEW', false),
    'preview_user_id' => env('PERSONNEL_PREVIEW_USER_ID'),
    'cas' => [
        'login_url' => env('CAS_LOGIN_URL'),
        'validate_url' => env('CAS_VALIDATE_URL'),
        'logout_url' => env('CAS_LOGOUT_URL'),
        'service_url' => env('CAS_SERVICE_URL'),
    ],
    'modules' => [
        'profile' => ['menu_id' => 303, 'table' => 'h50', 'title' => '个人信息'],
        'corrections' => ['menu_id' => 333, 'all_menu_ids' => [303, 333], 'table' => 'h50', 'title' => '更正申请'],
        'correction_review' => ['role_id' => 15, 'table' => 'h50', 'title' => '更正审核'],
        'history' => ['menu_id' => 336, 'menu_ids' => [336, 338, 341], 'table' => 'h70,h60,h53', 'title' => '档案履历'],
        'assessments' => ['menu_id' => 379, 'table' => 'h20', 'title' => '年度考核'],
        'assessment_review' => ['menu_id' => 380, 'table' => 'h20', 'title' => '部门考核审核'],
        'leave' => ['menu_id' => 408, 'table' => 'a34', 'title' => '请假申请'],
        'leave_details' => ['menu_id' => 411, 'table' => 'a35', 'title' => '我的请假明细'],
        'leave_progress' => ['menu_id' => 409, 'table' => 'a34', 'title' => '请假审批进度'],
        'leave_cancel' => ['menu_id' => 426, 'table' => 'a38', 'title' => '销假申请'],
        'leave_review' => ['menu_ids' => [412, 413], 'table' => 'a34,a38', 'title' => '请假审批'],
    ],
];
