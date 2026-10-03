<?php

namespace App\Domain\Media\Library\Http\Controllers;

use App\Domain\Media\Library\Models\MediaAsset;
use App\Domain\Media\Library\Models\MediaCollection;
use App\Domain\Media\Library\Models\MediaCollectionItem;
use App\Domain\Media\Library\Services\MediaLibraryService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class AdminMediaLibraryController extends Controller
{
    public function __construct(private readonly MediaLibraryService $library)
    {
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:160',
            'application_id' => 'nullable|integer|min:1|exists:applications,id',
            'kind' => 'nullable|string|in:image,video,document',
            'category' => 'nullable|string|max:80',
            'purpose' => 'nullable|string|max:80',
            'visibility' => 'nullable|string|in:private,public',
            'status' => 'nullable|string|in:ready,processing,archived',
            'is_official' => 'nullable|boolean',
            'is_marketing_approved' => 'nullable|boolean',
            'is_ai_generated' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        return response()->json([
            'assets' => $this->library->paginate($filters),
        ]);
    }

    public function show(MediaAsset $asset)
    {
        return response()->json([
            'asset' => $this->library->serialize($asset),
        ]);
    }

    public function store(Request $request)
    {
        $maxKb = max(1024, (int) config('media_library.max_upload_kb', 153600));
        $data = $request->validate([
            'application_id' => 'required|integer|min:1|exists:applications,id',
            'file' => [
                'nullable',
                'file',
                'max:'.$maxKb,
                'mimetypes:'.implode(',', (array) config('media_library.allowed_mime_types', [])),
            ],
            'public_url' => 'nullable|url:https|max:2048',
            'kind' => 'nullable|string|in:image,video,document',
            'owner_user_id' => 'nullable|integer|min:1|exists:users,id',
            'name' => 'nullable|string|max:255',
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:5000',
            'alt_text' => 'nullable|string|max:500',
            'category' => 'nullable|string|max:80',
            'purpose' => 'nullable|string|max:80',
            'mime_type' => 'nullable|string|max:120',
            'visibility' => 'nullable|string|in:private,public',
            'is_official' => 'nullable|boolean',
            'is_marketing_approved' => 'nullable|boolean',
            'is_ai_generated' => 'nullable|boolean',
            'metadata' => 'nullable|array',
        ]);

        abort_if($request->hasFile('file') && ! empty($data['public_url']), 422, 'Envie um arquivo ou informe uma URL, não ambos.');
        abort_if(! $request->hasFile('file') && empty($data['public_url']), 422, 'Envie um arquivo ou informe uma URL HTTPS.');

        try {
            $asset = $request->hasFile('file')
                ? $this->library->createUpload(
                    (int) $data['application_id'],
                    $request->user()?->id,
                    $request->file('file'),
                    $data
                )
                : $this->library->createExternal(
                    (int) $data['application_id'],
                    $request->user()?->id,
                    (string) $data['public_url'],
                    $data
                );
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Mídia adicionada à biblioteca.',
            'asset' => $this->library->serialize($asset),
        ], 201);
    }

    public function update(Request $request, MediaAsset $asset)
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'title' => 'sometimes|nullable|string|max:255',
            'description' => 'sometimes|nullable|string|max:5000',
            'alt_text' => 'sometimes|nullable|string|max:500',
            'category' => 'sometimes|required|string|max:80',
            'purpose' => 'sometimes|required|string|max:80',
            'visibility' => 'sometimes|required|string|in:private,public',
            'status' => 'sometimes|required|string|in:ready,processing,archived',
            'is_official' => 'sometimes|boolean',
            'is_marketing_approved' => 'sometimes|boolean',
            'is_ai_generated' => 'sometimes|boolean',
            'metadata' => 'sometimes|nullable|array',
        ]);

        $updated = $this->library->update($asset, $request->user()?->id, $data);

        return response()->json([
            'message' => 'Mídia atualizada.',
            'asset' => $this->library->serialize($updated),
        ]);
    }

    public function destroy(MediaAsset $asset)
    {
        $this->library->archive($asset);

        return response()->json(['message' => 'Mídia arquivada.']);
    }

    public function addRelation(Request $request, MediaAsset $asset)
    {
        $data = $request->validate([
            'entity_type' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'entity_id' => 'required|string|max:191',
            'role' => 'nullable|string|max:80',
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'metadata' => 'nullable|array',
        ]);

        return response()->json([
            'relation' => $this->library->addRelation($asset, $data),
        ], 201);
    }

    public function deleteRelation(MediaAsset $asset, int $relationId)
    {
        $deleted = $asset->relations()->whereKey($relationId)->delete();
        abort_unless($deleted, 404, 'Relação não encontrada.');

        return response()->json(['message' => 'Relação removida.']);
    }

    public function collections(Request $request)
    {
        $data = $request->validate([
            'application_id' => 'nullable|integer|min:1|exists:applications,id',
        ]);

        return response()->json([
            'collections' => $this->library->collectionList(
                isset($data['application_id']) ? (int) $data['application_id'] : null
            ),
        ]);
    }

    public function storeCollection(Request $request)
    {
        $data = $request->validate([
            'application_id' => 'required|integer|min:1|exists:applications,id',
            'owner_user_id' => 'nullable|integer|min:1|exists:users,id',
            'name' => 'required|string|max:160',
            'description' => 'nullable|string|max:3000',
            'purpose' => 'nullable|string|max:80',
            'visibility' => 'nullable|string|in:private,public',
            'status' => 'nullable|string|in:active,archived',
            'metadata' => 'nullable|array',
        ]);

        return response()->json([
            'collection' => $this->library->createCollection(
                (int) $data['application_id'],
                $request->user()?->id,
                $data
            ),
        ], 201);
    }

    public function attachCollection(Request $request, MediaCollection $collection, MediaAsset $asset)
    {
        $data = $request->validate([
            'sort_order' => 'nullable|integer|min:0|max:100000',
            'metadata' => 'nullable|array',
        ]);

        $this->library->attachToCollection($collection, $asset, $data);

        return response()->json(['message' => 'Mídia adicionada à coleção.']);
    }

    public function detachCollection(MediaCollection $collection, MediaAsset $asset)
    {
        MediaCollectionItem::query()
            ->where('media_collection_id', $collection->id)
            ->where('media_asset_id', $asset->id)
            ->delete();

        return response()->json(['message' => 'Mídia removida da coleção.']);
    }
}
