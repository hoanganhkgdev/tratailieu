<?php

namespace Tests\Feature;

use App\Filament\Pages\ImportTemples;
use App\Filament\Resources\DocumentResource\Pages\ListDocuments;
use App\Filament\Resources\DocumentResource\Pages\ViewDocument;
use App\Filament\Resources\TempleResource\Pages\ListTemples;
use App\Filament\Resources\TempleResource\Pages\ViewTemple;
use App\Jobs\ProcessTempleDocumentJob;
use App\Livewire\TempleChat;
use App\Models\Document;
use App\Models\Monastic;
use App\Models\Province;
use App\Models\Temple;
use App\Models\User;
use App\Services\TempleSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class TempleModuleSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_import_temples_page_renders(): void
    {
        Livewire::test(ImportTemples::class)->assertSuccessful();
    }

    public function test_temple_list_page_renders(): void
    {
        Livewire::test(ListTemples::class)->assertSuccessful();
    }

    public function test_temple_view_page_renders_with_monastics(): void
    {
        $province = Province::create(['name' => 'An Giang', 'aliases' => ['Kiên Giang']]);
        $temple = Temple::create([
            'province_id' => $province->id,
            'code'        => '0001',
            'name'        => 'Tịnh xá Ngọc Hưng',
            'type'        => 'tinh_xa',
            'address'     => 'Ấp Xẻo Rô, xã An Biên',
            'head_monk'   => 'Thích Nữ Toàn Liên',
            'phone'       => '0824031111',
        ]);
        $document = Document::create([
            'temple_id' => $temple->id,
            'file_path' => 'temples/0001.docx',
            'file_name' => '0001.docx',
            'file_type' => 'docx',
            'status'    => 'ready',
        ]);
        $temple->update(['latest_document_id' => $document->id]);
        Monastic::create([
            'temple_id'      => $temple->id,
            'document_id'    => $document->id,
            'stt'            => 1,
            'full_name'      => 'Trần Thị Mười',
            'religious_name' => 'Toàn Liên',
            'rank'           => 'Tỳ Kheo Ni',
            'position'       => 'Trụ trì',
            'birth_year'     => 1964,
        ]);

        Livewire::test(ViewTemple::class, ['record' => $temple->getRouteKey()])
            ->assertSuccessful();

        $this->assertCount(1, $temple->fresh()->monastics);
        $this->assertStringContainsString('0001.docx', $temple->fresh()->latestDocument->download_url);
    }

    public function test_document_list_page_renders_with_stats_widget(): void
    {
        Document::create([
            'file_path' => 'temples/a.docx',
            'file_name' => 'a.docx',
            'file_type' => 'docx',
            'status'    => 'failed',
            'error_message' => 'Không xác định được tỉnh/thành.',
        ]);

        Livewire::test(ListDocuments::class)->assertSuccessful();
    }

    public function test_document_view_page_shows_error_and_extracted_json(): void
    {
        $document = Document::create([
            'file_path'      => 'temples/b.docx',
            'file_name'      => 'b.docx',
            'file_type'      => 'docx',
            'status'         => 'failed',
            'error_message'  => 'Không xác định được tỉnh/thành.',
            'extracted_json' => [
                'code'      => '0002',
                'name'      => 'Chùa Test',
                'monastics' => [
                    ['stt' => 1, 'full_name' => 'Nguyễn Văn A', 'rank' => 'Đại đức'],
                ],
            ],
        ]);

        Livewire::test(ViewDocument::class, ['record' => $document->getRouteKey()])
            ->assertSuccessful()
            ->assertSee('Không xác định được tỉnh/thành.');
    }

    public function test_failed_document_can_be_retried(): void
    {
        Queue::fake();

        $document = Document::create([
            'file_path'     => 'temples/c.docx',
            'file_name'     => 'c.docx',
            'file_type'     => 'docx',
            'status'        => 'failed',
            'error_message' => 'Lỗi giả lập',
        ]);

        Livewire::test(ListDocuments::class)
            ->callTableAction('retry', $document);

        $document->refresh();

        $this->assertSame('pending', $document->status);
        $this->assertNull($document->error_message);

        Queue::assertPushed(ProcessTempleDocumentJob::class);
    }

    public function test_temple_search_service_matches_by_name_address_and_monastic(): void
    {
        $province = Province::create(['name' => 'An Giang', 'aliases' => ['Kiên Giang']]);
        $temple = Temple::create([
            'province_id' => $province->id,
            'code'        => '0004',
            'name'        => 'CHÙA DÂN BỬU',
            'type'        => 'chua',
            'address'     => 'Ấp Kinh Dài, xã Tây Yên',
            'head_monk'   => 'Thích Minh Tâm',
            'phone'       => '0329759936',
        ]);
        Monastic::create([
            'temple_id'      => $temple->id,
            'full_name'      => 'Phạm Thanh Lâm',
            'religious_name' => 'Thích Minh Tri',
            'rank'           => 'Sa Di',
            'position'       => 'Tăng chúng',
        ]);

        $search = app(TempleSearchService::class);

        // Dùng đúng chữ hoa/thường như dữ liệu gốc — SQLite (DB test) không gập
        // hoa/thường cho ký tự có dấu như MySQL thật (đã xác nhận riêng qua tinker
        // với MySQL rằng "dân bửu" khớp được "CHÙA DÂN BỬU").
        $this->assertTrue($search->search('DÂN BỬU')->contains('id', $temple->id));
        $this->assertTrue($search->search('0329759936')->contains('id', $temple->id));
        $this->assertTrue($search->search('Thích Minh Tri')->contains('id', $temple->id));
        $this->assertTrue($search->search('không tồn tại xyz')->isEmpty());
    }

    public function test_public_chat_route_requires_login(): void
    {
        \Illuminate\Support\Facades\Auth::logout();

        $this->get('/tra-cuu')->assertRedirect('/admin/login');
    }

    public function test_public_chat_component_renders_when_authenticated(): void
    {
        Livewire::test(TempleChat::class)->assertSuccessful();
    }

    public function test_public_chat_page_renders_through_full_http_stack(): void
    {
        $this->get('/tra-cuu')
            ->assertOk()
            ->assertSee('Tra cứu tự viện')
            ->assertSee('Hỏi gì về tự viện cũng được');
    }
}
