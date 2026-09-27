<?php

namespace App\Domain\AppContent\Repositories;

use App\Domain\AppContent\Models\GuestAppContent;
use App\Domain\AppContent\Repositories\Contracts\GuestAppContentRepositoryInterface;

class EloquentGuestAppContentRepository implements GuestAppContentRepositoryInterface
{
    public function current(): GuestAppContent
    {
        return GuestAppContent::query()->oldest('id')->first()
            ?? GuestAppContent::create()->refresh();
    }

    public function update(GuestAppContent $content, array $data): GuestAppContent
    {
        $content->update($data);

        return $content->refresh();
    }
}
