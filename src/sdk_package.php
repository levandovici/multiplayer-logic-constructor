<?php

// SDK packaging — builds the downloadable zips server-side with a small
// pure-PHP writer (ZipArchive isn't guaranteed on shared hosting; zlib is).
// Sources of truth live in sdks/<slug>/ — downloads are always built from
// current sources, never from stale committed zips.

const MLC_SDK_VERSION = '0.1.0';

// slug => title shown in the cabinet download section.
const MLC_SDKS = [
    'dotnet' => 'C# / .NET',
    'unity'  => 'Unity',
    'unreal' => 'Unreal Engine',
    'cpp'    => 'C++',
];

// Map of slug => zip-path => absolute source path. Unity ships the shared
// .NET client plus its own MonoBehaviour + MiniJSON.
function sdk_package_files(string $slug): array {
    $root = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
    $sdk = "$root/sdks/$slug";
    $files = [];
    switch ($slug) {
        case 'dotnet':
            $files = [
                'MlcClient.cs' => "$sdk/MlcClient.cs",
                'README.md'    => "$sdk/README.md",
            ];
            break;
        case 'unity':
            $files = [
                'MlcClient.cs'          => "$root/sdks/dotnet/MlcClient.cs",
                'MlcClientBehaviour.cs' => "$sdk/MlcClientBehaviour.cs",
                'MiniJSON.cs'           => "$sdk/MiniJSON.cs",
                'README.md'             => "$sdk/README.md",
            ];
            break;
        case 'unreal':
            $files = [
                'MlcClient.h'   => "$sdk/MlcClient.h",
                'MlcClient.cpp' => "$sdk/MlcClient.cpp",
                'README.md'     => "$sdk/README.md",
            ];
            break;
        case 'cpp':
            $files = [
                'mlc_client.hpp' => "$sdk/mlc_client.hpp",
                'mlc_client.cpp' => "$sdk/mlc_client.cpp",
                'README.md'      => "$sdk/README.md",
            ];
            break;
        default:
            return [];
    }
    foreach ($files as $path) {
        if (!is_file($path)) return [];   // source missing → no zip
    }
    return $files;
}

// Minimal ZIP writer (store/deflate via zlib). Deterministic: fixed DOS
// timestamp so identical sources produce identical bytes — useful for tests.
function zip_build(array $files): string {
    $out = '';
    $central = '';
    $dostime = (0 << 11) | (0 << 5) | (0 >> 1);   // 00:00:00
    $dosdate = ((1980 - 1980) << 9) | (1 << 5) | 1; // 1980-01-01

    foreach ($files as $name => $path) {
        $data = (string)file_get_contents($path);
        $crc = crc32($data);
        $comp = gzdeflate($data, 6);
        if ($comp === false || strlen($comp) >= strlen($data)) {
            $comp = $data;
            $method = 0;                 // stored
        } else {
            $method = 8;                 // deflate
        }
        $offset = strlen($out);

        // Local file header
        $out .= 'PK' . "\x03\x04" . pack('vvvvvVVVvv',
            20, 0, $method, $dostime, $dosdate,
            $crc, strlen($comp), strlen($data), strlen($name), 0);
        $out .= $name . $comp;

        // Central directory entry
        $central .= 'PK' . "\x01\x02" . pack('vvvvvvVVVvvvvvVV',
            20, 20, 0, $method, $dostime, $dosdate,
            $crc, strlen($comp), strlen($data),
            strlen($name), 0, 0, 0, 0, 0, $offset);
        $central .= $name;
    }

    $out .= $central;
    $out .= 'PK' . "\x05\x06" . pack('vvvvVVv',
        0, 0, count($files), count($files), strlen($central),
        strlen($central) ? strlen($out) - strlen($central) : 0, 0);
    return $out;
}

function sdk_zip(string $slug): ?string {
    $files = sdk_package_files($slug);
    return $files === [] ? null : zip_build($files);
}

function sdk_zip_name(string $slug): string {
    return 'mlc-' . $slug . '-sdk-' . MLC_SDK_VERSION . '.zip';
}
