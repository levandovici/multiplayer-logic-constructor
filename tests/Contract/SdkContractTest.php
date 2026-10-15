<?php

namespace Mlc\Tests\Contract;

use PHPUnit\Framework\TestCase;

// Guards the SDK sources against wire-protocol drift: every SDK must send
// the documented endpoints, the auth headers, and honor the *_json
// convention (Unity/Unreal). Pure source-level assertions — the .NET SDK is
// additionally compile-tested by tests/Sdk/DotNetCheck (CI runs dotnet run).
class SdkContractTest extends TestCase {
    private static function src(string $path): string {
        $file = dirname(__DIR__, 2) . '/sdks/' . $path;
        self::assertFileExists($file);
        return (string)file_get_contents($file);
    }

    /** Every SDK must hit the real endpoints. */
    public function testEndpointsAppearInAllSdks(): void {
        $must = [
            'dotnet/MlcClient.cs'   => ['action=register', 'action=login',
                'action=updates', 'action=create', 'action=join', 'action=leave',
                'logic.php?action=', 'LogicCallAsync("do"',
                'LogicCallAsync("message"', 'state.php', 'X-Api-Token',
                'X-Player-Token'],
            'unity/MlcClientBehaviour.cs' => ['MlcClient', 'OnUpdate'],
            'cpp/mlc_client.cpp'    => ['action=register', 'action=login',
                'action=updates', 'action=create', 'action=join', 'action=leave',
                'logic.php?action=', 'logicCall("do"', 'logicCall("message"',
                'state.php', 'X-Api-Token', 'X-Player-Token'],
            'unreal/MlcClient.cpp'  => ['action=register', 'action=login',
                'action=updates', 'action=create', 'action=join', 'action=leave',
                'logic.php?action=', 'LogicCall(TEXT("do")',
                'LogicCall(TEXT("message")', 'state.php', 'X-Api-Token',
                'X-Player-Token'],
        ];
        foreach ($must as $file => $needles) {
            $src = self::src($file);
            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $src,
                    "$file missing wire marker: $needle");
            }
        }
    }

    /** Unity/Unreal must use the *_json convention — never serialize
     *  free-form objects into nested payload fields. */
    public function testJsonStringConvention(): void {
        // Unity behaviour delegates to DoJsonAsync/SendJsonAsync which send
        // payload_json; check the marker where it actually appears.
        $unity = self::src('unity/MlcClientBehaviour.cs');
        $this->assertStringContainsString('DoJsonAsync', $unity);
        $this->assertStringContainsString('payloadJson', $unity);
        $this->assertStringContainsString('payload_json', self::src('unreal/MlcClient.cpp'));
        // dotnet/cpp accept both forms.
        foreach (['dotnet/MlcClient.cs', 'cpp/mlc_client.cpp'] as $f) {
            $src = self::src($f);
            $this->assertStringContainsString('payload_json', $src, "$f: payload_json missing");
            $this->assertStringContainsString('payload', $src, "$f: payload missing");
        }
        // Incoming *_json fields must be surfaced.
        $this->assertStringContainsString('data_json', self::src('dotnet/MlcClient.cs'));
        $this->assertStringContainsString('data_json', self::src('cpp/mlc_client.cpp'));
        $this->assertStringContainsString('data_json', self::src('unreal/MlcClient.cpp'));
    }

    /** The shared client file is the unity one's dependency — same file
     *  must land in both zips. */
    public function testUnityZipSharesClientSource(): void {
        require_once dirname(__DIR__, 2) . '/src/sdk_package.php';
        $files = sdk_package_files('unity');
        $this->assertArrayHasKey('MlcClient.cs', $files);
        $this->assertStringEndsWith('sdks/dotnet/MlcClient.cs',
            str_replace('\\', '/', $files['MlcClient.cs']));
    }

    /** ZIP writer roundtrip: signatures, central dir, deflate payload
     *  gzinflate-able back to source. */
    public function testZipBuildRoundtrip(): void {
        require_once dirname(__DIR__, 2) . '/src/sdk_package.php';
        $payload = str_repeat('hello sdk world! ', 200);   // deflates well
        $tmp = tempnam(sys_get_temp_dir(), 'sdk');
        file_put_contents($tmp, $payload);
        $zip = zip_build(['test/file.txt' => $tmp]);
        unlink($tmp);

        $this->assertStringStartsWith("PK\x03\x04", $zip);
        $this->assertStringContainsString("PK\x01\x02", $zip, 'no central dir');
        $this->assertStringContainsString("PK\x05\x06", $zip, 'no EOCD');
        $this->assertStringContainsString('test/file.txt', $zip);
        // Local header: 30 bytes + name → compressed data follows.
        $nameLen = strlen('test/file.txt');
        $csize = unpack('V', $zip, 18)[1];           // csize field @ offset 18
        $comp = substr($zip, 30 + $nameLen, $csize);
        $this->assertSame($payload, (string)gzinflate($comp));
    }

    /** All four SDKs must actually build — the cabinet download endpoint
     *  serves this exact output. */
    public function testAllSdksPackage(): void {
        require_once dirname(__DIR__, 2) . '/src/sdk_package.php';
        foreach (array_keys(MLC_SDKS) as $slug) {
            $zip = sdk_zip($slug);
            $this->assertNotNull($zip, "$slug sources missing");
            $this->assertStringStartsWith("PK\x03\x04", $zip);
            $this->assertGreaterThan(1024, strlen($zip), "$slug zip suspiciously small");
        }
    }
}
