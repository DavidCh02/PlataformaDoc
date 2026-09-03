<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DocumentUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public int $documentId,
        public int $userId,
        public string $delta = '',
        public ?string $content = null,
    ) {
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('document.'.$this->documentId)];
    }

    public function broadcastAs(): string
    {
        return 'DocumentUpdated';
    }

    public function broadcastWith(): array
    {
        return [
            'document_id' => $this->documentId,
            'delta' => $this->delta,
            'content' => $this->content,
            'user_id' => $this->userId,
        ];
    }
}
