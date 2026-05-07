<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessRequest extends Model
{
    protected $fillable = ['name', 'ip', 'location', 'user_agent', 'status', 'expires_at'];
    protected $casts    = ['expires_at' => 'datetime'];

    public function isActiveApproval(): bool
    {
        return $this->status === 'approved' && $this->expires_at?->isFuture();
    }

    public static function findApprovedByIp(string $ip): ?self
    {
        return static::where('ip', $ip)
            ->where('status', 'approved')
            ->where('expires_at', '>', now())
            ->latest()
            ->first();
    }
}
