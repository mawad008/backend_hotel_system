<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\AppContent\Models\GuestAppContent;
use App\Domain\AppContent\Services\GuestAppContentService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\AppContent\UpdateGuestAppContentRequest;
use App\Http\Requests\Api\V1\AppContent\UploadGuestAppImageRequest;
use App\Http\Resources\V1\GuestAppContentResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff editor for the Guest App's branding + entry content
 * (`/api/v1/app-content`) — `app-content.manage`. The guest app reads the
 * same content anonymously via Guest\GuestAppContentController.
 */
class GuestAppContentController extends Controller
{
    public function __construct(private readonly GuestAppContentService $contents) {}

    public function show(): JsonResponse
    {
        $content = $this->contents->current();
        $this->authorize('view', $content);

        return $this->success(new GuestAppContentResource($content));
    }

    public function update(UpdateGuestAppContentRequest $request): JsonResponse
    {
        $this->authorize('update', $this->contents->current());

        $content = $this->contents->updateText($request->validated(), $request->user());

        return $this->success(new GuestAppContentResource($content), __('api.updated'));
    }

    public function uploadImage(UploadGuestAppImageRequest $request): JsonResponse
    {
        $this->authorize('update', $this->contents->current());

        $content = $this->contents->uploadImage($request->slot(), $request->file('image'), $request->user());

        return $this->success(new GuestAppContentResource($content), __('api.updated'));
    }

    public function removeImage(Request $request, string $slot): JsonResponse
    {
        $this->authorize('update', $this->contents->current());

        if (! in_array($slot, GuestAppContent::IMAGE_SLOTS, true)) {
            abort(404);
        }

        $content = $this->contents->removeImage($slot, $request->user());

        return $this->success(new GuestAppContentResource($content), __('api.updated'));
    }
}
