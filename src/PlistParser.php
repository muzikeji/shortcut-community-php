<?php
namespace Shortcut;

use CFPropertyList\CFPropertyList;

class PlistParser {
    private static array $permissionActions = [
        'savetocameraroll' => '照片', 'getphotos' => '照片', 'selectphotos' => '照片',
        'setwallpaper' => '壁纸',
        'contact' => '通讯录', 'getcontacts' => '通讯录', 'selectcontact' => '通讯录',
        'getcalendar' => '日历', 'calendar' => '日历', 'createevent' => '日历',
        'sendmessage' => '信息',
        'sendemail' => '邮件',
        'call' => '电话',
        'facetime' => 'FaceTime',
        'getlocation' => '定位', 'getcurrentlocation' => '定位', 'openinmaps' => '定位',
        'reminders' => '提醒事项', 'addnewreminder' => '提醒事项', 'getreminders' => '提醒事项',
        'gethealth' => '健康', 'health' => '健康', 'savedetailsfromfitnessapp' => '健康',
        'playmusic' => '音乐', 'getcurrentsong' => '音乐',
        'files' => '文件', 'getfile' => '文件', 'createfolder' => '文件',
        'getfolder' => '文件', 'getcontentsoffolder' => '文件',
        'getitemsofdata' => '文件', 'savetofile' => '文件',
        'runapp' => '打开应用', 'openapp' => '打开应用', 'launchapp' => '打开应用', 'open' => '打开应用',
        'setclipboard' => '剪贴板',
        'getbatterylevel' => '电池',
        'wifi' => '网络', 'getwifi' => '网络', 'wifi-connect' => '网络',
        'vpn' => 'VPN',
        'bluetooth' => '蓝牙', 'getbluetooth' => '蓝牙',
        'sendnotification' => '通知', 'notification' => '通知', 'shownotification' => '通知',
        'vibrate' => '振动',
        'flashlight' => '手电筒',
        'setbrightness' => '屏幕亮度',
        'setvolume' => '音量',
        'lowpowermode' => '低电量模式',
        'donotdisturb' => '专注模式', 'focus' => '专注模式',
        'startworkout' => '体能训练', 'workout' => '体能训练',
        'recordaudio' => '麦克风', 'recordaudiomemo' => '麦克风',
        'takephoto' => '相机', 'takephotovideo' => '相机', 'video' => '相机',
        'scanqrcode' => '相机', 'scan' => '相机',
        'getscreenbrightness' => '屏幕亮度',
        'appstore' => 'App Store', 'searchappstore' => 'App Store', 'lookup' => 'App Store',
    ];

    public static function parseShortcutInfo(string $shortcutUrl): ?array {
        if (!$shortcutUrl) return null;
        try {
            $ctx = stream_context_create([
                'http' => [
                    'timeout' => 15,
                    'header' => "User-Agent: Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15\r\n",
                ],
                'socket' => [
                    'timeout' => 15,
                ],
            ]);
            $data = file_get_contents($shortcutUrl, false, $ctx);
            if (!$data) return null;

            $plist = new CFPropertyList();
            $plist->parse($data);
            $arr = $plist->toArray();

            $wf = $arr;
            if (!isset($wf['WFWorkflowActions'])) return null;

            $actions = $wf['WFWorkflowActions'];
            $actionNames = [];
            $permissions = [];
            foreach ($actions as $a) {
                $ident = $a['WFWorkflowActionIdentifier'] ?? '';
                $last = substr(strrchr($ident, '.'), 1);
                $actionNames[] = $ident;
                if (isset(self::$permissionActions[$last])) {
                    $permissions[self::$permissionActions[$last]] = true;
                }
            }

            $minVersion = '';
            $rawVersion = $wf['WFWorkflowMinimumClientVersion'] ?? $wf['WFWorkflowMinimumSystemVersion'] ?? null;
            if ($rawVersion !== null) {
                $num = (int) $rawVersion;
                if ($num >= 100 && strpos((string) $rawVersion, '.') === false) {
                    $minVersion = 'iOS ' . intdiv($num, 100) . '.' . ($num % 100);
                } else {
                    $v = explode('.', (string) $rawVersion);
                    $minVersion = 'iOS ' . implode('.', array_slice($v, 0, 2));
                }
            }

            $uniqueActions = array_unique(array_map(function($a) {
                $parts = explode('.', $a);
                return end($parts);
            }, $actionNames));

            return [
                'actionCount' => count($actions),
                'size' => strlen($data),
                'permissions' => array_keys($permissions),
                'actionTypes' => $actionNames,
                'name' => $wf['WFWorkflowName'] ?? '',
                'minVersion' => $minVersion,
                'workflowTypes' => $wf['WFWorkflowTypes'] ?? [],
                'importQuestions' => count($wf['WFWorkflowImportQuestions'] ?? []),
                'distinctActionCount' => count($uniqueActions),
            ];
        } catch (\Exception $e) {
            return null;
        }
    }
}
