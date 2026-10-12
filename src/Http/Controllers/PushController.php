<?php

namespace ChristianHnl\PowerMetrics\Http\Controllers;

use ChristianHnl\PowerMetrics\Models\PowerSource;
use ChristianHnl\PowerMetrics\Services\PowerRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use InvalidArgumentException;

class PushController extends Controller
{
    public function __invoke(Request $request, PowerRecorder $recorder): JsonResponse
    {
        $token = $request->bearerToken() ?: $request->input('token');

        if (!is_string($token) || $token === '') {
            return response()->json(['error' => 'Token fehlt.'], 401);
        }

        /** @var ?PowerSource $source */
        $source = PowerSource::query()
            ->where('direct_method', PowerSource::DIRECT_PUSH)
            ->where('enabled', true)
            ->where('token', $token)
            ->first();

        if (!$source || !hash_equals((string) $source->token, $token)) {
            return response()->json(['error' => 'Ungültiges Token.'], 401);
        }

        $watts = $request->input('watts');

        if (!is_numeric($watts)) {
            return response()->json(['error' => '"watts" muss eine Zahl sein.'], 422);
        }

        try {
            $stored = $recorder->record($source, (float) $watts + $source->direct_offset_watts, method: PowerSource::METHOD_DIRECT);

            // Push läuft wieder: einen alten "Push-Skript sendet nicht"-Hinweis entfernen
            $source->forceFill(['last_error' => null])->saveQuietly();
        } catch (InvalidArgumentException $exception) {
            return response()->json(['error' => $exception->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'stored' => $stored]);
    }
}
