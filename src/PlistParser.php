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

            try {
                $plist = new CFPropertyList();
                $plist->parse($data);
                $arr = $plist->toArray();
            } catch (\Throwable $e) {
                $arr = self::parseBinaryPlist($data);
                if ($arr === null) return null;
            }

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

    public static function parseBinaryPlist(string $data): ?array {
        if (strlen($data) < 40 || substr($data, 0, 8) !== 'bplist00') return null;

        $trailer = substr($data, -32);
        $offsetIntSize = ord($trailer[6]);
        $objectRefSize = ord($trailer[7]);
        $numObjects = self::readBEInt(substr($trailer, 8, 8));
        $topObject = self::readBEInt(substr($trailer, 16, 8));
        $tableOffset = self::readBEInt(substr($trailer, 24, 8));

        if ($numObjects < 1 || $offsetIntSize < 1 || $objectRefSize < 1) return null;

        $offsets = [];
        for ($i = 0; $i < $numObjects; $i++) {
            $offsets[] = self::readBEInt(substr($data, $tableOffset + $i * $offsetIntSize, $offsetIntSize));
        }

        try {
            return self::readObject($data, $offsets, $objectRefSize, $topObject);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function readBEInt(string $bytes): int {
        $val = 0;
        for ($i = 0, $n = strlen($bytes); $i < $n; $i++) {
            $val = ($val << 8) | ord($bytes[$i]);
        }
        return $val;
    }

    private static function readLength(string $data, int &$pos): int {
        $marker = ord($data[$pos]);
        $nbytes = 1 << ($marker & 0x0F);
        if ($nbytes > 8) $nbytes = 8;
        $val = self::readBEInt(substr($data, $pos + 1, $nbytes));
        $pos += 1 + $nbytes;
        return $val;
    }

    private static function readObject(string $data, array $offsets, int $refSize, int $index): mixed {
        $offset = $offsets[$index];
        $marker = ord($data[$offset]);
        $type = $marker >> 4;
        $info = $marker & 0x0F;
        $pos = $offset + 1;

        switch ($type) {
            case 0x0:
                if ($info === 0x8) return false;
                if ($info === 0x9) return true;
                return null;
            case 0x1:
                $nbytes = 1 << $info;
                if ($nbytes > 8) $nbytes = 8;
                return self::readBEInt(substr($data, $pos, $nbytes));
            case 0x2:
                $nbytes = 1 << $info;
                if ($nbytes === 4) return unpack('G', substr($data, $pos, 4))[1];
                return unpack('E', substr($data, $pos, 8))[1];
            case 0x3:
                return unpack('E', substr($data, $pos, 8))[1] + 978307200;
            case 0x4:
                $len = $info === 0x0F ? self::readLength($data, $pos) : $info;
                return substr($data, $pos, $len);
            case 0x5:
                $len = $info === 0x0F ? self::readLength($data, $pos) : $info;
                return substr($data, $pos, $len);
            case 0x6:
                $len = $info === 0x0F ? self::readLength($data, $pos) : $info;
                return mb_convert_encoding(substr($data, $pos, $len), 'UTF-8', 'UTF-16BE');
            case 0x8:
                return 'uid:' . self::readBEInt(substr($data, $pos, $info));
            case 0xA:
                $count = $info === 0x0F ? self::readLength($data, $pos) : $info;
                $arr = [];
                for ($i = 0; $i < $count; $i++) {
                    $ref = self::readBEInt(substr($data, $pos, $refSize));
                    $pos += $refSize;
                    $arr[] = self::readObject($data, $offsets, $refSize, $ref);
                }
                return $arr;
            case 0xD:
                $count = $info === 0x0F ? self::readLength($data, $pos) : $info;
                $keyRefs = [];
                for ($i = 0; $i < $count; $i++) {
                    $keyRefs[] = self::readBEInt(substr($data, $pos, $refSize));
                    $pos += $refSize;
                }
                $dict = [];
                for ($i = 0; $i < $count; $i++) {
                    $valRef = self::readBEInt(substr($data, $pos, $refSize));
                    $pos += $refSize;
                    $key = self::readObject($data, $offsets, $refSize, $keyRefs[$i]);
                    $dict[$key] = self::readObject($data, $offsets, $refSize, $valRef);
                }
                return $dict;
        }
        return null;
    }
}
