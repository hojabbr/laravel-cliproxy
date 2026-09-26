<?php

namespace Hojabbr\CliProxy\Ai\Gateways;

use Illuminate\Support\Collection;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Gateway\OpenAiCompatible\OpenAiCompatibleGateway;

/**
 * Text through the gateway's `/v1/chat/completions` endpoint (the `chat-completions` protocol,
 * the default).
 *
 * The gateway translates the request for whichever upstream serves the model. Beyond laravel/ai's
 * own gateway for this format, documents are sent as `file` content parts (the gateway reads their
 * data URLs), and a 500 fails over for the same reason as {@see MessagesGateway}.
 */
class ChatCompletionsGateway extends OpenAiCompatibleGateway
{
    /**
     * {@inheritdoc}
     *
     * @param  Collection<array-key, mixed>  $attachments
     * @return list<mixed>
     */
    protected function mapAttachments(Collection $attachments): array
    {
        $parts = [];

        foreach ($attachments as $attachment) {
            $parts[] = $attachment instanceof Document
                ? ['type' => 'file', 'file' => [
                    'filename' => $attachment->name() ?? 'document',
                    'file_data' => 'data:'.($attachment->mimeType() ?? 'application/pdf').';base64,'.base64_encode($attachment->content()),
                ]]
                : parent::mapAttachments(new Collection([$attachment]))[0];
        }

        return $parts;
    }

    /**
     * {@inheritdoc}
     *
     * @return list<int>
     */
    protected function overloadedStatusCodes(): array
    {
        return array_values(array_filter([500, ...parent::overloadedStatusCodes()], is_int(...)));
    }
}
