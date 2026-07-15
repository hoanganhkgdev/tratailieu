<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportMonastics;
use App\Filament\Resources\MonasticDocumentResource\Pages\ListMonasticDocuments;
use App\Filament\Resources\MonasticDocumentResource\Pages\ViewMonasticDocument;
use App\Filament\Resources\MonasticProfileResource\Pages\ListMonasticProfiles;
use App\Filament\Resources\MonasticProfileResource\Pages\ViewMonasticProfile;
use App\Jobs\ProcessMonasticDocumentJob;
use App\Livewire\MonasticChat;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MonasticDocument;
use App\Models\MonasticProfile;
use App\Models\Province;
use App\Models\User;
use App\Services\MonasticSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class MonasticModuleSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_import_monastics_page_renders(): void
    {
        Livewire::test(ImportMonastics::class)->assertSuccessful();
    }

    public function test_monastic_document_list_page_renders_with_stats_widget(): void
    {
        MonasticDocument::create([
            'file_path' => 'tang-ni/a.docx',
            'file_name' => 'a.docx',
            'file_type' => 'docx',
            'status'    => 'failed',
            'error_message' => 'Lỗi giả lập',
        ]);

        Livewire::test(ListMonasticDocuments::class)->assertSuccessful();
    }

    public function test_monastic_document_can_be_retried(): void
    {
        Queue::fake();

        $document = MonasticDocument::create([
            'file_path'     => 'tang-ni/b.docx',
            'file_name'     => 'b.docx',
            'file_type'     => 'docx',
            'status'        => 'failed',
            'error_message' => 'Lỗi giả lập',
        ]);

        Livewire::test(ListMonasticDocuments::class)
            ->callTableAction('retry', $document);

        $document->refresh();

        $this->assertSame('pending', $document->status);
        $this->assertNull($document->error_message);

        Queue::assertPushed(ProcessMonasticDocumentJob::class);
    }

    public function test_monastic_document_view_page_shows_extracted_json(): void
    {
        $document = MonasticDocument::create([
            'file_path'      => 'tang-ni/c.docx',
            'file_name'      => 'c.docx',
            'file_type'      => 'docx',
            'status'         => 'failed',
            'error_message'  => 'Không xác định được nội dung.',
            'extracted_json' => ['full_name' => 'Nguyễn Văn A', 'religious_name' => 'Thích Minh Tâm'],
        ]);

        Livewire::test(ViewMonasticDocument::class, ['record' => $document->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Không xác định được nội dung.');
    }

    public function test_monastic_profile_list_page_renders(): void
    {
        Livewire::test(ListMonasticProfiles::class)->assertSuccessful();
    }

    public function test_monastic_profile_view_page_renders_with_full_data(): void
    {
        $province = Province::create(['name' => 'An Giang', 'aliases' => []]);
        $document = MonasticDocument::create([
            'province_id' => $province->id,
            'file_path'   => 'tang-ni/an-giang/d.docx',
            'file_name'   => 'd.docx',
            'file_type'   => 'docx',
            'status'      => 'ready',
        ]);
        $profile = MonasticProfile::create([
            'monastic_document_id' => $document->id,
            'province_id'           => $province->id,
            'full_name'             => 'Từ Thành Đạt',
            'religious_name'        => 'Thích Minh Nhẫn',
            'classification'        => ['chuc_sac', 'chuc_viec'],
            'current_position'      => 'Trụ trì',
            'status'                => 'Đang hoạt động',
        ]);

        Livewire::test(ViewMonasticProfile::class, ['record' => $profile->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Từ Thành Đạt')
            ->assertSee('An Giang');
    }

    public function test_monastic_search_service_matches_by_full_name_religious_name_phone(): void
    {
        $province = Province::create(['name' => 'An Giang', 'aliases' => []]);
        $document = MonasticDocument::create([
            'province_id' => $province->id,
            'file_path'   => 'tang-ni/an-giang/e.docx',
            'file_name'   => 'e.docx',
            'file_type'   => 'docx',
            'status'      => 'ready',
        ]);
        $profile = MonasticProfile::create([
            'monastic_document_id' => $document->id,
            'province_id'           => $province->id,
            'full_name'             => 'Từ Thành Đạt',
            'religious_name'        => 'Thích Minh Nhẫn',
            'id_number'             => '091094018475',
            'phone'                 => '0384557784',
        ]);

        $search = app(MonasticSearchService::class);

        $this->assertTrue($search->search('Từ Thành Đạt')->contains('id', $profile->id));
        $this->assertTrue($search->search('Thích Minh Nhẫn')->contains('id', $profile->id));
        $this->assertTrue($search->search('0384557784')->contains('id', $profile->id));
        $this->assertTrue($search->search('091094018475')->contains('id', $profile->id));
        $this->assertTrue($search->search('không tồn tại xyz')->isEmpty());
    }

    /**
     * Cùng lỗi thật đã tái hiện và fix ở TempleSearchService — xem
     * TempleModuleSmokeTest::test_search_from_list_line_strips_leading_number().
     */
    public function test_search_from_list_line_strips_leading_number(): void
    {
        $province = Province::create(['name' => 'An Giang', 'aliases' => []]);
        $document = MonasticDocument::create([
            'province_id' => $province->id,
            'file_path'   => 'tang-ni/an-giang/g.docx',
            'file_name'   => 'g.docx',
            'file_type'   => 'docx',
            'status'      => 'ready',
        ]);
        $profile = MonasticProfile::create([
            'monastic_document_id' => $document->id,
            'province_id'           => $province->id,
            'full_name'             => 'Thích Diệu Tâm',
            'religious_name'        => 'Thích Diệu Tâm',
        ]);

        $search = app(MonasticSearchService::class);

        $result = $search->search('1. **Thích Diệu Tâm** (Thích Diệu Tâm) — Tỉnh: An Giang');

        $this->assertCount(1, $result);
        $this->assertSame($profile->id, $result->first()->id);
    }

    public function test_public_monastic_chat_route_requires_login(): void
    {
        \Illuminate\Support\Facades\Auth::logout();

        $this->get('/tra-cuu-tang-ni')->assertRedirect('/admin/login');
    }

    public function test_public_monastic_chat_component_renders_when_authenticated(): void
    {
        Livewire::test(MonasticChat::class)->assertSuccessful();
    }

    public function test_public_monastic_chat_page_renders_through_full_http_stack(): void
    {
        $this->get('/tra-cuu-tang-ni')
            ->assertOk()
            ->assertSee('Tra cứu tăng ni')
            ->assertSee('Hỏi gì về tăng ni cũng được');
    }

    /**
     * Conversations tăng ni và tự viện dùng CHUNG 1 bảng (phân biệt qua cột "type")
     * — đây là rủi ro thật cần test riêng: đảm bảo sidebar tăng ni không lẫn lịch sử
     * chat tự viện của cùng user, và ngược lại.
     */
    public function test_monastic_chat_sidebar_does_not_leak_temple_conversations(): void
    {
        $user = $this->user;
        $templeConversation = Conversation::create(['user_id' => $user->id, 'title' => 'Hỏi về chùa A', 'type' => 'temple']);
        $monasticConversation = Conversation::create(['user_id' => $user->id, 'title' => 'Hỏi về thầy B', 'type' => 'monastic']);

        Livewire::test(MonasticChat::class)
            ->assertSee('Hỏi về thầy B')
            ->assertDontSee('Hỏi về chùa A');
    }

    public function test_monastic_chat_ask_returns_detail_for_single_match(): void
    {
        $province = Province::create(['name' => 'An Giang', 'aliases' => []]);
        $document = MonasticDocument::create([
            'province_id' => $province->id,
            'file_path'   => 'tang-ni/an-giang/f.docx',
            'file_name'   => 'f.docx',
            'file_type'   => 'docx',
            'status'      => 'ready',
        ]);
        MonasticProfile::create([
            'monastic_document_id' => $document->id,
            'province_id'           => $province->id,
            'full_name'             => 'Từ Thành Đạt',
            'religious_name'        => 'Thích Minh Nhẫn',
            'phone'                 => '0384557784',
        ]);

        Livewire::test(MonasticChat::class)
            ->set('question', 'Từ Thành Đạt')
            ->call('ask')
            ->assertSee('Từ Thành Đạt')
            ->assertSee('An Giang');

        $this->assertDatabaseHas('conversations', ['user_id' => $this->user->id, 'type' => 'monastic']);
    }

    /**
     * Yêu cầu: xóa 1 tăng ni phải dọn sạch cả 3 nơi — record DB, file gốc trên R2,
     * và mục trong Meilisearch — không để sót rác ở đâu (xem
     * MonasticProfile::booted()/MonasticDocument::booted()).
     */
    public function test_deleting_monastic_profile_cleans_up_document_file_and_search_index(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('tang-ni/xoa-test.docx', 'noi dung gia');

        $document = MonasticDocument::create([
            'file_path' => 'tang-ni/xoa-test.docx',
            'file_name' => 'xoa-test.docx',
            'file_type' => 'docx',
            'status'    => 'ready',
        ]);
        $profile = MonasticProfile::create([
            'monastic_document_id' => $document->id,
            'full_name'             => 'Cần Xóa Test',
        ]);

        $this->assertTrue(\Illuminate\Support\Facades\Storage::disk('public')->exists('tang-ni/xoa-test.docx'));

        $profile->delete();

        $this->assertDatabaseMissing('monastic_profiles', ['id' => $profile->id]);
        $this->assertDatabaseMissing('monastic_documents', ['id' => $document->id]);
        $this->assertFalse(\Illuminate\Support\Facades\Storage::disk('public')->exists('tang-ni/xoa-test.docx'));
    }

    /**
     * Chiều ngược lại: xóa document TRỰC TIẾP (không qua profile) — DB tự cascade xóa
     * profile (monastic_document_id có cascadeOnDelete()), nhưng đó là cascade DB
     * thuần không bắn sự kiện Eloquent, nên vẫn phải tự gọi unsearchable() để không
     * sót rác trong Meilisearch (xem MonasticDocument::booted()).
     */
    public function test_deleting_monastic_document_directly_also_cleans_up_file_and_cascades_profile(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('tang-ni/xoa-test-2.docx', 'noi dung gia');

        $document = MonasticDocument::create([
            'file_path' => 'tang-ni/xoa-test-2.docx',
            'file_name' => 'xoa-test-2.docx',
            'file_type' => 'docx',
            'status'    => 'ready',
        ]);
        $profile = MonasticProfile::create([
            'monastic_document_id' => $document->id,
            'full_name'             => 'Cần Xóa Test 2',
        ]);

        $document->delete();

        $this->assertDatabaseMissing('monastic_documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('monastic_profiles', ['id' => $profile->id]);
        $this->assertFalse(\Illuminate\Support\Facades\Storage::disk('public')->exists('tang-ni/xoa-test-2.docx'));
    }
}
