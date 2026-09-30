<?php

namespace App\Http\Controllers\Roadmap\Admin;

use App\Http\Requests\Roadmap\Admin\MarkdownPreviewRequest;
use App\Services\Roadmap\MarkdownRenderer;
use Illuminate\Http\JsonResponse;

class MarkdownController extends AdminController
{
    public function __construct(private MarkdownRenderer $markdown) {}

    /** POST /markdown/preview { md } -> { html } (identical renderer to the stored HTML). */
    public function preview(MarkdownPreviewRequest $request): JsonResponse
    {
        return $this->run(fn () => $this->ok(
            ['html' => $this->markdown->render($request->validated('md'))],
            'Preview generated successfully'
        ));
    }
}
