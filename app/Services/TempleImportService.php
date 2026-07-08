<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Monastic;
use App\Models\Province;
use App\Models\Temple;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenAI\Laravel\Facades\OpenAI;

class TempleImportService
{
    /**
     * Giá gpt-4o-mini, đơn vị USD trên mỗi 1 token (không phải 1M) — nhân trực tiếp
     * với số token trả về từ OpenAI để ra chi phí thực từng lần gọi.
     */
    private const INPUT_COST_PER_TOKEN = 0.15 / 1_000_000;

    private const OUTPUT_COST_PER_TOKEN = 0.60 / 1_000_000;

    public function __construct(private DocumentParserService $parser) {}

    public function process(Document $document): void
    {
        $data = null;

        try {
            $document->update(['status' => 'processing']);

            $text = $this->parser->extractText($document->file_path, $document->file_type);
            $data = $this->analyze($document, $text);

            // Nếu admin đã chọn sẵn tỉnh lúc upload thì dùng luôn, không để AI tự đoán —
            // tránh trường hợp AI nhầm tên huyện/xã cũ (vd "An Minh") thành tên tỉnh.
            $province = $document->province ?? Province::findByNameOrAlias($data['province_name'] ?? null);

            if (! $province) {
                $document->update([
                    'status'         => 'failed',
                    'error_message'  => 'Không xác định được tỉnh/thành từ tài liệu. Vui lòng kiểm tra và gán thủ công.',
                    'extracted_json' => $data,
                ]);

                return;
            }

            $this->finalize($document, $data, $province);
        } catch (\Throwable $e) {
            $document->update([
                'status'         => 'failed',
                'error_message'  => $e->getMessage(),
                'extracted_json' => $data,
            ]);
        }
    }

    /**
     * Dùng khi AI trích xuất được dữ liệu nhưng không tự đối chiếu được tỉnh/thành
     * (vd tài liệu chỉ ghi tên huyện cũ). Tái sử dụng JSON đã có, không gọi lại AI.
     */
    public function assignProvince(Document $document, Province $province): void
    {
        $data = $document->extracted_json;

        if (! is_array($data)) {
            throw new \RuntimeException('Tài liệu chưa có dữ liệu AI trích xuất để gán tỉnh.');
        }

        try {
            $this->finalize($document, $data, $province);
        } catch (\Throwable $e) {
            $document->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
            ]);
        }
    }

    private function finalize(Document $document, array $data, Province $province): void
    {
        $name = $data['name'] ?? 'Chưa xác định';

        // Không dựa vào mã ghi trong văn bản nữa — tài liệu thực tế rất hay thiếu mã hoặc
        // AI dễ lấy nhầm số khác (địa chỉ, điện thoại, số liệu rác trong file) làm mã, từng
        // gây gộp nhầm nhiều tự viện khác nhau. Hệ thống tự quản lý mã, đơn giản và nhất quán
        // cho mọi tỉnh: trước hết thử tìm tự viện CÙNG TỈNH đã có tên trùng khớp (chạy lại
        // lệnh, hay lỡ trùng file trong 1 đợt import) để tái dùng đúng mã cũ, tránh tạo trùng
        // lặp — không tìm thấy mới cấp mã số tuần tự mới.
        $normalizedName = Str::of($name)->lower()->squish()->toString();
        $existing = Temple::withTrashed()
            ->where('province_id', $province->id)
            ->whereRaw('LOWER(TRIM(name)) = ?', [$normalizedName])
            ->first();

        $code = $existing ? $existing->code : $this->nextSequentialCode($province);

        // Nhiều worker có thể cùng lúc xử lý 2 tài liệu trỏ về cùng 1 mã tự viện (vd
        // upload trùng file). Khoá theo (tỉnh, mã) để việc ghi Temple + đồng bộ chức sắc
        // không chồng lấn giữa các job — chờ tối đa 15s rồi mới chịu thua.
        Cache::lock("temple-import:{$province->id}:{$code}", 30)->block(15, function () use ($document, $data, $province, $code, $name) {
            // updateOrCreate() làm 2 bước riêng (tìm rồi tạo) nên vẫn có thể đụng unique
            // (province_id, code) nếu lock bị timeout; upsert() là 1 câu lệnh DB atomic
            // nên an toàn tuyệt đối dù có race.
            //
            // Unique index (province_id, code) không biết đến deleted_at, nên nếu mã này
            // từng bị xoá (soft delete) rồi tài liệu được upload lại, phải chủ động hồi
            // sinh (deleted_at = null) — nếu không upsert sẽ âm thầm cập nhật vào đúng bản
            // ghi đã xoá mà nó vẫn ẩn khỏi mọi truy vấn thường.
            Temple::upsert(
                [[
                    'province_id' => $province->id,
                    'code'        => $code,
                    'name'        => $name,
                    'slug'        => Str::slug($code.'-'.$name),
                    'type'        => $this->normalizeType($data['type'] ?? null),
                    'address'     => $data['address'] ?? null,
                    'head_monk'   => $data['head_monk'] ?? null,
                    'phone'       => $this->truncate($data['phone'] ?? null),
                    'is_active'   => true,
                    'deleted_at'  => null,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]],
                ['province_id', 'code'],
                ['name', 'slug', 'type', 'address', 'head_monk', 'phone', 'is_active', 'deleted_at', 'updated_at']
            );

            $temple = Temple::withTrashed()->where('province_id', $province->id)->where('code', $code)->firstOrFail();

            $document->update(['temple_id' => $temple->id]);

            // Tài liệu mới nhất là nguồn chuẩn cho danh sách chức sắc hiện tại của tự viện,
            // nên thay hẳn danh sách cũ thay vì cố gắng đối chiếu từng dòng.
            $temple->monastics()->delete();

            foreach ($data['monastics'] ?? [] as $row) {
                Monastic::create([
                    'temple_id'      => $temple->id,
                    'document_id'    => $document->id,
                    'stt'            => $row['stt'] ?? null,
                    'full_name'      => $row['full_name'] ?? 'Chưa xác định',
                    'religious_name' => $row['religious_name'] ?? null,
                    'rank'           => $row['rank'] ?? null,
                    'position'       => $row['position'] ?? null,
                    'birth_year'     => $row['birth_year'] ?? null,
                    'phone'          => $this->truncate($row['phone'] ?? null),
                ]);
            }

            $temple->update(['latest_document_id' => $document->id]);

            $document->update([
                'status'         => 'ready',
                'processed_at'   => now(),
                'error_message'  => null,
                'extracted_json' => $data,
            ]);
        });
    }

    private function truncate(?string $value, int $length = 50): ?string
    {
        return $value === null ? null : mb_substr(trim($value), 0, $length);
    }

    /**
     * Cấp mã số tuần tự tiếp theo cho 1 tỉnh (dạng "0001", "0002"...) khi tài liệu
     * không có mã thật. Lần đầu tiên của 1 tỉnh, số bắt đầu được suy từ mã số lớn
     * nhất đã tồn tại (kể cả mã thật trích từ AI trước đây, nếu còn), để không đè
     * lên mã đã dùng.
     *
     * Từng thử atomic UPDATE kiểu MySQL (LAST_INSERT_ID(expr) trong INSERT...SELECT)
     * — ra kết quả sai khó hiểu ở thực tế. Sau đó thử transaction + lockForUpdate()
     * thuần SQL — 8 worker cùng lúc "INSERT IGNORE" dòng đếm MỚI (lần đầu của 1
     * tỉnh) bị MySQL báo deadlock thật (SQLSTATE 40001, đã tái hiện được bằng test
     * 8 tiến trình song song). Fix cuối: khoá bằng Cache::lock() (đã dùng ổn định ở
     * chỗ khác trong file này, không đụng InnoDB row lock nên không thể deadlock)
     * để cả nhóm 8 worker phải xếp hàng tuần tự khi vào đoạn tính số này.
     */
    private function nextSequentialCode(Province $province): string
    {
        return Cache::lock("temple-code-seq:{$province->id}", 30)->block(15, function () use ($province) {
            $ignoreKeyword = DB::getDriverName() === 'sqlite' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';

            DB::statement(
                "{$ignoreKeyword} INTO temple_code_sequences (province_id, next_number, created_at, updated_at) VALUES (?, 0, ?, ?)",
                [$province->id, now(), now()]
            );

            $row = DB::table('temple_code_sequences')->where('province_id', $province->id)->first();

            $next = (int) $row->next_number;

            if ($next === 0) {
                $next = Temple::withTrashed()
                    ->where('province_id', $province->id)
                    ->pluck('code')
                    ->filter(fn ($c) => ctype_digit((string) $c))
                    ->map(fn ($c) => (int) $c)
                    ->max() ?? 0;
            }

            $next++;

            DB::table('temple_code_sequences')
                ->where('province_id', $province->id)
                ->update(['next_number' => $next, 'updated_at' => now()]);

            return str_pad((string) $next, 4, '0', STR_PAD_LEFT);
        });
    }

    /**
     * Phần hướng dẫn này giữ NGUYÊN VĂN giống nhau ở mọi lần gọi — luôn đặt trước
     * phần nội dung file (thay đổi theo từng tài liệu) để OpenAI tự áp dụng
     * "prompt caching" (giảm ~50% giá phần này từ lần gọi thứ 2 trở đi). Nếu đảo
     * thứ tự (nội dung file trước, hướng dẫn sau) sẽ mất hẳn phần tiết kiệm này.
     */
    private const INSTRUCTIONS = <<<PROMPT
Hãy phân tích văn bản trích từ hồ sơ một tự viện Phật giáo Việt Nam (được cung cấp ở cuối
prompt này) và trả về JSON.

Văn bản thường có phần đầu ghi: tên tự viện (có thể kèm số/mã đứng trước, bỏ qua phần số này —
hệ thống tự quản lý mã riêng, không cần lấy từ văn bản), địa chỉ, tên trụ trì, số điện thoại của
trụ trì. Sau đó là một bảng liệt kê các vị chức sắc, chức việc, nhà tu hành trong tự viện. Cấu
trúc cột của bảng KHÔNG cố định giữa các tài liệu, ví dụ:
- Có tài liệu tách riêng cột "Pháp danh" và cột "Giới phẩm" (Tỳ Kheo Ni, Sa di, Ngũ Giới...).
- Có tài liệu gộp chung thành 1 cột "Giáo phẩm/Pháp danh" (ví dụ giá trị "Hòa thượng Thích Thanh Tùng"),
  cột này có thể nằm TRƯỚC hoặc SAU cột "Họ và tên" tuỳ tài liệu — đọc theo đúng tên tiêu đề cột,
  không giả định thứ tự cố định. Trường hợp gộp PHẢI tách ra: phần chức danh tôn giáo đưa vào "rank",
  phần còn lại (tên trong đạo) đưa vào "religious_name".
- Chức danh tôn giáo có thể viết đầy đủ (Hòa thượng, Thượng tọa, Ni trưởng, Ni sư, Sư cô, Đại đức,
  Tỳ Kheo, Tỳ Kheo Ni, Thức Xoa, Sa di, Sa di Ni, Ngũ Giới...) hoặc viết tắt kèm dấu chấm (HT., TT.,
  NT., NS., SC., ĐĐ.) hoặc viết tắt liền không dấu chấm (TXMN = Thức xoa ma na, SDN = Sa di ni,
  SD = Sa di). Luôn nhận diện cả dạng viết tắt.
- Tên cột có thể khác nhau giữa tài liệu nhưng cùng ý nghĩa: "STT" hoặc "TT" (số thứ tự), "Chức việc"
  hoặc "Chức vụ" hoặc "Chức sắc/Chức việc" (đều đưa vào "position").
- Cột năm sinh có thể chỉ ghi năm (vd 1964, 2005), đưa vào "birth_year" dạng số nguyên, không có
  phần thập phân.
- Một số tài liệu có thêm cột điện thoại riêng cho từng người, một số thì không.
- KHÔNG cần đọc hay trả về mã số tự viện trong văn bản (nếu có số đứng trước tên, đó chỉ là số
  thứ tự nội bộ của tài liệu gốc, không dùng) — chỉ cần lấy đúng tên tự viện, bỏ hẳn phần số/mã
  phía trước nếu có.

Trả về JSON đúng định dạng sau (field nào không có thì để null, mảng rỗng nếu không có ai):
{
  "name": "tên tự viện, không kèm mã số phía trước",
  "type": "chua | tu_vien | tinh_xa | thien_vien | tinh_that",
  "province_name": "tên tỉnh/thành phố (vd: An Giang, TP. Hồ Chí Minh)",
  "address": "địa chỉ đầy đủ",
  "head_monk": "tên trụ trì",
  "phone": "số điện thoại của trụ trì / liên hệ chung",
  "monastics": [
    {
      "stt": 1,
      "full_name": "họ và tên khai sinh",
      "religious_name": "pháp danh / tên trong đạo",
      "rank": "giáo phẩm hoặc giới phẩm",
      "position": "chức việc, ví dụ: Trụ trì, Phó trụ trì, Tăng chúng",
      "birth_year": 1964,
      "phone": "điện thoại riêng nếu có, ngược lại null"
    }
  ]
}

Chỉ trả về JSON thuần, không giải thích thêm, không bọc trong markdown code block.

Văn bản cần phân tích:
PROMPT;

    private function analyze(Document $document, string $text): array
    {
        // Có tự viện thật lên tới 36+ chức sắc (bị OpenAI cắt giữa dòng khi giới
        // hạn cũ 2500 token — một số chùa Khmer cộng đồng lớn hơn hẳn phần còn
        // lại của dữ liệu mẫu). Nới rộng cả input/output để không cắt mất dữ liệu
        // thật của các tự viện lớn.
        $excerpt = Str::limit($text, 12000);

        $response = OpenAI::chat()->create([
            'model'           => 'gpt-4o-mini',
            'response_format' => ['type' => 'json_object'],
            'temperature'     => 0,
            // ~36 người đã tốn ~2500 token và vẫn bị cắt — nâng lên 12000 để chịu
            // được tự viện vài trăm người mà chi phí vẫn không đáng kể ($0.60/1M).
            'max_tokens'      => 12000,
            'messages'        => [
                ['role' => 'user', 'content' => self::INSTRUCTIONS."\n\n".$excerpt],
            ],
        ]);

        $usage = $response->usage;

        $document->update([
            'ai_input_tokens'  => $usage->promptTokens,
            'ai_output_tokens' => $usage->completionTokens,
            'ai_cost_usd'      => ($usage->promptTokens * self::INPUT_COST_PER_TOKEN)
                + ($usage->completionTokens * self::OUTPUT_COST_PER_TOKEN),
        ]);

        if ($response->choices[0]->finishReason === 'length') {
            throw new \RuntimeException(
                'AI bị cắt phản hồi giữa dòng vì tự viện có quá nhiều chức sắc (vượt giới hạn token). '.
                'Cần tăng max_tokens trong TempleImportService hoặc xử lý tài liệu này riêng.'
            );
        }

        $raw = $response->choices[0]->message->content ?? '';

        // Văn bản trích xuất từ file gốc đôi khi lẫn ký tự điều khiển (control
        // character) hoặc byte không hợp lệ UTF-8 do lỗi encoding/định dạng cũ —
        // nếu AI lặp lại nguyên trong JSON trả về, json_decode sẽ báo lỗi. Dọn
        // UTF-8 hỏng trước (mb_convert_encoding tự bỏ byte không hợp lệ), rồi mới
        // strip ký tự điều khiển thô — KHÔNG dùng flag /u ở bước này vì nếu chuỗi
        // vẫn còn sót byte hỏng, preg_replace('/u') sẽ lặng lẽ trả về null và
        // code sẽ vô tình dùng lại bản gốc chưa được dọn.
        $sanitized = mb_convert_encoding($raw, 'UTF-8', 'UTF-8');
        $sanitized = preg_replace('/[\x00-\x1F\x7F]/', ' ', $sanitized) ?? $sanitized;
        $data      = json_decode(trim($sanitized), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
            throw new \RuntimeException(
                'AI không trả về JSON hợp lệ: '.json_last_error_msg().
                ' — đoạn đầu phản hồi: '.Str::limit(trim($sanitized), 300)
            );
        }

        return $data;
    }

    private function normalizeType(?string $type): string
    {
        $valid = ['chua', 'tu_vien', 'tinh_xa', 'thien_vien', 'tinh_that'];

        if (in_array($type, $valid, true)) {
            return $type;
        }

        $map = [
            'chùa'       => 'chua',
            'tự viện'    => 'tu_vien',
            'tu vien'    => 'tu_vien',
            'tịnh xá'    => 'tinh_xa',
            'tinh xa'    => 'tinh_xa',
            'thiền viện' => 'thien_vien',
            'thien vien' => 'thien_vien',
            'tịnh thất'  => 'tinh_that',
            'tinh that'  => 'tinh_that',
        ];

        $normalized = mb_strtolower(trim($type ?? ''));

        return $map[$normalized] ?? 'chua';
    }
}
