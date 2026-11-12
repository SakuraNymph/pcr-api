<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Role;
use App\Models\User;
use App\Models\UserFeedback;
use App\Models\UserFeedbackImage;
use App\Services\FeedbackImageService;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsFeedbackSchema;
use Tests\TestCase;

class FeedbackImageAccessTest extends TestCase
{
    use BuildsFeedbackSchema;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00'));
        $this->createFeedbackSchema();
        Storage::fake('local');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_feedback_image_is_stored_privately_and_signed_url_works(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $feedbackId = $this->submitFeedbackWithImage($user);

        $image = UserFeedbackImage::query()->firstOrFail();

        $this->assertTrue(Storage::disk('local')->exists($image->path));
        $this->assertStringStartsWith('feedback/'.$feedbackId.'/', $image->path);

        $url = $this->getJson('/api/user/feedback/'.$feedbackId)
            ->assertOk()
            ->json('data.messages.0.images.0.url');

        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('viewer_id', $url);

        $this->get($url)
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_tampered_viewer_token_is_rejected(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $feedbackId = $this->submitFeedbackWithImage($user);

        $url = $this->getJson('/api/user/feedback/'.$feedbackId)->json('data.messages.0.images.0.url');
        $tampered = preg_replace('/viewer=[^&]+/', 'viewer=tampered-token', $url);

        $this->get($tampered)->assertStatus(403);
    }

    public function test_expired_signed_url_is_rejected(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $feedbackId = $this->submitFeedbackWithImage($user);

        $image = UserFeedbackImage::query()->firstOrFail();
        $viewer = app(FeedbackImageService::class)->encodeViewer(
            FeedbackImageService::VIEWER_USER,
            (int) $user->id,
            $feedbackId
        );

        $expired = URL::temporarySignedRoute(
            'user.feedback.image',
            now()->subMinute(),
            ['image' => $image->id, 'viewer' => $viewer]
        );

        $this->get($expired)->assertStatus(403);
    }

    public function test_user_viewer_cannot_be_used_on_admin_image_route(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $feedbackId = $this->submitFeedbackWithImage($user);

        $image = UserFeedbackImage::query()->firstOrFail();
        $userViewer = app(FeedbackImageService::class)->encodeViewer(
            FeedbackImageService::VIEWER_USER,
            (int) $user->id,
            $feedbackId
        );

        $adminUrl = URL::temporarySignedRoute(
            'admin.feedback.image',
            now()->addMinutes(30),
            ['image' => $image->id, 'viewer' => $userViewer]
        );

        $this->get($adminUrl)->assertStatus(403);
    }

    public function test_admin_can_access_image_with_admin_signed_url(): void
    {
        $user = $this->createUser();
        $feedback = $this->createFeedbackFor($user);
        $image = $this->attachImage($feedback, $user);

        $admin = $this->createSuperAdmin();

        $url = app(FeedbackImageService::class)->signedUrl(
            FeedbackImageService::VIEWER_ADMIN,
            (int) $admin->id,
            (int) $feedback->id,
            $image
        );

        $this->get($url)->assertOk();
    }

    public function test_missing_file_returns_404(): void
    {
        $user = $this->createUser();
        $feedback = $this->createFeedbackFor($user);
        $image = $this->attachImage($feedback, $user);

        $url = $this->getJson('/api/user/feedback/'.$feedback->id)->json('data.messages.0.images.0.url');

        Storage::disk('local')->delete($image->path);

        $this->get($url)->assertStatus(404);
    }

    public function test_non_image_file_is_rejected(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $this->post('/api/user/feedback', [
            'category' => 1,
            'title' => '非法图片',
            'content' => '上传了一张 GIF 图片',
            'images' => [UploadedFile::fake()->image('bad.gif', 10, 10)],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
    }

    public function test_oversized_image_is_rejected(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $this->post('/api/user/feedback', [
            'category' => 1,
            'title' => '超大图片',
            'content' => '上传了一张超过 5MB 的图片',
            'images' => [UploadedFile::fake()->image('big.png', 10, 10)->size(5121)],
        ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['images.0']);
    }

    public function test_files_are_cleaned_up_when_database_write_fails(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        Schema::drop('user_feedback_images');

        $this->post('/api/user/feedback', [
            'category' => 1,
            'title' => '写入失败',
            'content' => '这次数据库写入会失败',
            'images' => [UploadedFile::fake()->image('a.png', 10, 10)],
        ], ['Accept' => 'application/json'])
            ->assertStatus(500);

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame(0, UserFeedback::query()->count());
    }

    private function submitFeedbackWithImage(User $user): int
    {
        $response = $this->post('/api/user/feedback', [
            'category' => 1,
            'title' => '带图片的反馈',
            'content' => '反馈内容附带一张图片',
            'images' => [UploadedFile::fake()->image('shot.png', 20, 20)],
        ], ['Accept' => 'application/json'])->assertOk();

        return (int) $response->json('data.id');
    }

    private function createFeedbackFor(User $user): UserFeedback
    {
        $feedback = UserFeedback::query()->create([
            'user_id' => $user->id,
            'public_uid' => $user->public_uid,
            'nickname' => $user->nickname,
            'category' => UserFeedback::CATEGORY_USAGE,
            'title' => '图片反馈',
            'status' => UserFeedback::STATUS_PENDING,
            'client_type' => 'h5',
            'client_version' => '1.0.0',
            'last_message_at' => now(),
        ]);

        $feedback->messages()->create([
            'sender_type' => 1,
            'sender_id' => $user->id,
            'content' => '初始反馈内容',
            'read_at' => null,
        ]);

        return $feedback;
    }

    private function attachImage(UserFeedback $feedback, User $user): UserFeedbackImage
    {
        $message = $feedback->messages()->firstOrFail();

        $file = UploadedFile::fake()->image('shot.png', 20, 20);
        $service = app(FeedbackImageService::class);
        $metadata = $service->store($file, (int) $feedback->id, (int) $message->id);

        $image = $message->images()->create($metadata);

        Sanctum::actingAs($user);

        return $image;
    }

    private function createUser(int $publicUid = 123456): User
    {
        return User::query()->create([
            'public_uid' => $publicUid,
            'ip' => '10.0.1.'.($publicUid % 200),
            'nickname' => '图片用户',
            'avatar' => 105811,
            'status' => 1,
            'is_subscribe' => 1,
        ]);
    }

    private function createSuperAdmin(): Admin
    {
        $role = Role::query()->firstOrCreate(
            ['code' => 'super_admin'],
            ['name' => '超级管理员', 'status' => 1]
        );

        $admin = Admin::query()->create([
            'username' => 'super_'.uniqid(),
            'password' => 'secret',
            'nickname' => '超级管理员',
            'status' => 1,
        ]);

        $admin->roles()->attach($role->id);

        return $admin;
    }
}
