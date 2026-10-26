<?php
// Optional alternate autoloader permits honest backend-only checks when Inertia is unavailable.
$root = dirname(__DIR__);
$autoload = $argv[1] ?? $root.'/vendor/autoload.php';
require $autoload;
if (isset($argv[1])) {
    putenv('COMPOSER_VENDOR_DIR='.str_replace('\\', '/', dirname(realpath($autoload))));
    putenv('APP_PACKAGES_CACHE=.cache/backend-check/packages.php');
    putenv('APP_SERVICES_CACHE=.cache/backend-check/services.php');
}
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$status = $kernel->call('migrate', ['--force' => true]);
echo $kernel->output();
if ($status !== 0) exit($status);
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
$probe = app(App\Services\VideoProbe::class);
$directory = storage_path('app/private/smoke-'.bin2hex(random_bytes(6)));
mkdir($directory,0777,true);
$paths = [];
$project = null;
try {
    foreach ([2,3600,3601] as $seconds) {
        $path = $directory.'/'.$seconds.'.mp4'; $paths[] = $path;
        (new Symfony\Component\Process\Process([config('video.ffmpeg_path'),'-v','error','-y','-f','lavfi','-i','color=c=green:s=64x64:r=1','-t',(string)$seconds,'-c:v','libx264','-pix_fmt','yuv420p',$path]))->setTimeout(60)->mustRun();
        if ($seconds <= 3600) check(abs($probe->inspect($path)['duration']-$seconds)<0.01, 'FFprobe accepts '.$seconds.' seconds');
        else {
            try { $probe->inspect($path); throw new RuntimeException('901-second video accepted'); }
            catch (Illuminate\Validation\ValidationException $e) { check(isset($e->errors()['video']), 'FFprobe rejects over 60 minutes'); }
        }
    }
    $path = $directory.'/audio.wav'; $paths[] = $path;
    (new Symfony\Component\Process\Process([config('video.ffmpeg_path'),'-v','error','-y','-f','lavfi','-i','sine=frequency=1000','-t','2',$path]))->mustRun();
    $corrupt = $directory.'/corrupt.mp4'; $paths[] = $corrupt; file_put_contents($corrupt,'invalid video');
    foreach ([$path,$corrupt] as $bad) {
        try { $probe->inspect($bad); throw new RuntimeException('Invalid file accepted'); }
        catch (Illuminate\Validation\ValidationException $e) { check(isset($e->errors()['video']), 'Reject '.basename($bad)); }
    }
    $project = App\Models\Project::create(['name'=>'Smoke test (temporary)']);
    $controller = app(App\Http\Controllers\MediaController::class);
    $upload = Illuminate\Http\Request::create('/projects/'.$project->id.'/media', 'POST', [], [], [
        'video' => new Illuminate\Http\UploadedFile($paths[0], 'short.mp4', 'video/mp4', null, true),
    ]);
    $controller->store($upload, $project, $probe);
    $media = $project->media()->firstOrFail();
    check($project->fresh()->media()->count()===1, 'Project and media persist in SQLite');
    $controller->update(Illuminate\Http\Request::create('/', 'PATCH', ['trim_start'=>0.5,'trim_end'=>1.5]), $project, $media);
    check($media->fresh()->trim_start===0.5 && $media->fresh()->trim_end===1.5, 'Trim markers persist');
    foreach ([[-1,1],[1,1],[1.5,1],[0,3]] as [$start,$end]) {
        try {
            $controller->update(Illuminate\Http\Request::create('/', 'PATCH', ['trim_start'=>$start,'trim_end'=>$end]), $project, $media);
            throw new RuntimeException('Invalid trim accepted');
        } catch (Illuminate\Validation\ValidationException $e) { check(true, 'Reject invalid trim '.$start.'–'.$end); }
    }
    $response = $controller->stream($project, $media);
    $response->prepare(Illuminate\Http\Request::create('/', 'GET', [], [], [], ['HTTP_RANGE'=>'bytes=0-9']));
    check($response->getStatusCode()===206 && $response->headers->get('Content-Length')==='10', 'Video stream supports byte ranges');
    $other = new App\Models\Project; $other->id = $project->id+999;
    try { $controller->stream($other, $media); throw new RuntimeException('Cross-project media accepted'); }
    catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e) { check(true,'Media is scoped to its project'); }
    $storedPath = $media->path;
    app(App\Http\Controllers\ProjectController::class)->destroy($project);
    check(!App\Models\Media::whereKey($media->id)->exists(), 'Project deletion cascades to media');
    check(!Illuminate\Support\Facades\Storage::exists($storedPath), 'Project deletion removes uploaded video');
    echo 'Backend smoke checks completed. This does not verify Inertia HTTP flows or the React UI.'.PHP_EOL;
} finally {
    $project?->delete();
    foreach ($paths as $path) { if (is_file($path)) unlink($path); }
    rmdir($directory);
}
