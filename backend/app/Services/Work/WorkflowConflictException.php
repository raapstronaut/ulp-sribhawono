<?php

namespace App\Services\Work;

use RuntimeException;

/**
 * Tindakan tidak sah untuk status saat ini (mis. melapor WO yang sudah selesai).
 * API membalas 409; Web Admin kembali ke halaman sebelumnya dengan pesan galat.
 */
class WorkflowConflictException extends RuntimeException {}
