<?php

return [
    // Keep production writes opt-in, separate from the general legacy read connection.
    'write_target' => env('PROFILE_CORRECTION_WRITE_TARGET', 'disabled'), // disabled, stage, production
    'production_writes_enabled' => env('PROFILE_CORRECTION_PRODUCTION_WRITES_ENABLED', false),
    'fields' => [
        'basic' => ['h5001', 'h5090', 'h5005', 'h5065', 'h5006', 'h5079', 'h5009', 'h5011', 'h5052'],
        'work' => ['h5012', 'h5013'],
        'education' => ['h5031', 'h5054', 'h5034', 'h5030', 'h5032', 'h5080', 'h5055', 'h5049', 'h5050'],
        'qualifications' => ['h5040', 'h5056', 'h5039', 'h5057', 'h5041', 'h5047', 'h5062', 'h5026', 'h5027', 'h5051'],
        'contact' => ['h5022', 'h5082', 'h5083', 'h5084', 'h5085', 'h5087', 'h5074', 'h5064', 'h5075', 'h5076'],
    ],
    'evidence_required' => [
        'h5001', 'h5005', 'h5006', 'h5079', 'h5009', 'h5011', 'h5052', 'h5012', 'h5013',
        'h5031', 'h5054', 'h5034', 'h5030', 'h5032', 'h5080', 'h5055', 'h5049', 'h5050',
        'h5040', 'h5056', 'h5039', 'h5057', 'h5041', 'h5047', 'h5062', 'h5026', 'h5027', 'h5051',
    ],
    'dates' => ['h5006', 'h5079', 'h5013', 'h5030', 'h5050', 'h5056', 'h5057', 'h5062', 'h5051'],
    'dictionaries' => [
        'h5005' => ['table' => 'userdictionary', 'code' => 'Id', 'label' => 'typeName', 'type' => 'gender', 'format' => 'id_label'],
        'h5065' => ['table' => 'd380', 'code' => 'objname', 'label' => 'objjc'],
        'h5009' => ['table' => 'd202', 'code' => 'objname', 'label' => 'objjc'],
        'h5011' => ['table' => 'd204', 'code' => 'objname', 'label' => 'objjc'],
        'h5052' => ['table' => 'd203', 'code' => 'objname', 'label' => 'objjc'],
        'h5012' => ['table' => 'd210', 'code' => 'objname', 'label' => 'objjc'],
        'h5031' => ['table' => 'd430', 'code' => 'Id', 'label' => 'objjc', 'format' => 'id_label'],
        'h5032' => ['table' => 'd235', 'code' => 'Id', 'label' => 'objjc', 'format' => 'id_label'],
        'h5080' => ['table' => 'ud16', 'code' => 'Id', 'label' => 'name', 'format' => 'id_label'],
        'h5040' => ['table' => 'd218', 'code' => 'objname', 'label' => 'objjc'],
        'h5039' => ['table' => 'd952', 'code' => 'objname', 'label' => 'objjc'],
        'h5047' => ['table' => 'd230', 'code' => 'objname', 'label' => 'objjc'],
        'h5026' => ['table' => 'gz_yesno', 'code' => 'objname', 'label' => 'name'],
        'h5022' => ['table' => 'gz_yesno', 'code' => 'objname', 'label' => 'name'],
        'h5082' => ['table' => 'd202', 'code' => 'objname', 'label' => 'objjc'],
    ],
];
