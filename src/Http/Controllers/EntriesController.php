<?php

namespace LaraGram\Sentinel\Http\Controllers;

use LaraGram\Http\Request;
use LaraGram\Sentinel\Contracts\EntriesRepository;
use LaraGram\Sentinel\EntryType;
use LaraGram\Sentinel\Storage\EntryQueryOptions;

class EntriesController extends Controller
{
    /**
     * List the entries of a type.
     *
     * @param  \LaraGram\Http\Request  $request
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $storage
     * @param  string|null  $type
     * @return \LaraGram\Http\JsonResponse
     */
    public function index(Request $request, EntriesRepository $storage, ?string $type = null)
    {
        if ($type !== null && ! in_array($type, EntryType::all(), true)) {
            $type = null;
        }

        $options = EntryQueryOptions::fromRequest($request);

        $entries = $storage->get($type, $options);

        return response()->json([
            'entries' => $entries->values(),
            'next' => $entries->count() >= $options->limit ? $entries->last()?->sequence : null,
        ]);
    }

    /**
     * Show an entry and everything recorded with it.
     *
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $storage
     * @param  string  $id
     * @return \LaraGram\Http\JsonResponse
     */
    public function show(EntriesRepository $storage, string $id)
    {
        try {
            $entry = $storage->find($id);
        } catch (\RuntimeException) {
            return response()->json(['message' => 'Entry not found.'], 404);
        }

        $batch = $storage->get(null, EntryQueryOptions::forBatchId($entry->batchId))
            ->reject(fn ($batchEntry) => $batchEntry->id === $entry->id)
            ->sortBy('sequence')
            ->values();

        $family = $entry->familyHash && $entry->type === EntryType::EXCEPTION
            ? $storage->get(EntryType::EXCEPTION, (new EntryQueryOptions)->familyHash($entry->familyHash)->limit(20))->values()
            : collect();

        return response()->json([
            'entry' => $entry,
            'batch' => $batch,
            'family' => $family,
        ]);
    }

    /**
     * Count the stored entries of each type.
     *
     * @param  \LaraGram\Sentinel\Contracts\EntriesRepository  $storage
     * @return \LaraGram\Http\JsonResponse
     */
    public function counts(EntriesRepository $storage)
    {
        return response()->json(method_exists($storage, 'counts') ? $storage->counts() : []);
    }
}
