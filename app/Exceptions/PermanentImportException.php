<?php

namespace App\Exceptions;

/**
 * Lỗi KHÔNG đáng thử lại — dữ liệu/định dạng file thật sự có vấn đề (không khớp mẫu,
 * không trích được ảnh nào...), thử lại bao nhiêu lần cũng ra cùng kết quả. Khác với
 * lỗi gọi Gemini thất bại (quá tải tạm thời, JSON cắt cụt...) — loại đó để exception
 * bay thẳng ra ProcessMonasticDocumentJob, cho Laravel tự retry sau vài phút.
 */
class PermanentImportException extends \RuntimeException {}
