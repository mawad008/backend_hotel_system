<?php

namespace App\Domain\AppContent\Repositories\Contracts;

use App\Domain\AppContent\Models\GuestAppContent;

interface GuestAppContentRepositoryInterface
{
    /**
     * The singleton content row, created empty on first read.
     */
    public function current(): GuestAppContent;

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(GuestAppContent $content, array $data): GuestAppContent;
}
