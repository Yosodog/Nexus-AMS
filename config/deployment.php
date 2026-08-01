<?php

return [
    'owner' => env('APP_DEPLOY_OWNER', 'www-data'),
    'group' => env('APP_DEPLOY_GROUP', 'www-data'),
    'directory_mode' => env('APP_DEPLOY_DIRECTORY_MODE', '0750'),
    'file_mode' => env('APP_DEPLOY_FILE_MODE', '0640'),
];
