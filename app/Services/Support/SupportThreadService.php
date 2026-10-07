<?php

namespace App\Services\Support;

use App\Data\PushNotificationData;
use App\Data\SupportThreadFilters;
use App\Events\SupportReplyPosted;
use App\Http\Resources\SupportMessageResource;
use App\Models\Support\SupportMessage;
use App\Models\Support\SupportThread;
use App\Models\User;
use App\Services\Account\PushNotificationService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Переписка пользователя с поддержкой: у каждого пользователя одно обращение. */
class SupportThreadService
{
    private const MAX_FILES = 10;

    private const MAX_BODY_LENGTH = 4000;

    private const AUTHOR = 'user:id,login,name,avatar,updated_at';

    public function __construct(
        private readonly SupportAttachmentService $attachments,
        private readonly PushNotificationService $push,
    ) {}

    public function findOrCreateThreadForUser(User $user): SupportThread
    {
        return SupportThread::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'status' => SupportThread::STATUS_OPEN,
                'category' => SupportThread::CATEGORY_INBOX,
                'user_last_read_at' => now(),
            ],
        );
    }

    /** Открывает переписку целиком и отмечает её прочитанной той стороной, которая её открыла. */
    public function openThread(SupportThread $thread, bool $byStaff = false): SupportThread
    {
        $thread->update([$byStaff ? 'admin_last_read_at' : 'user_last_read_at' => now()]);

        $thread->load([
            self::AUTHOR,
            'messages' => fn ($query) => $query->orderBy('id')->with(self::AUTHOR),
        ]);
        $thread->setAttribute('unread', false);

        return $thread;
    }

    /**
     * @return Collection<int, SupportThread>
     */
    public function listThreadsForAdmin(SupportThreadFilters $filters = new SupportThreadFilters): Collection
    {
        $sort = in_array($filters->sort ?? null, ['last_message_at', 'created_at', 'id'], true)
            ? $filters->sort
            : 'last_message_at';
        $order = ($filters->order ?? null) === 'asc' ? 'asc' : 'desc';
        $unread = $filters->unread ?? null;

        return SupportThread::query()
            ->where('category', $filters->category ?? SupportThread::CATEGORY_INBOX)
            ->with([self::AUTHOR, 'latestMessage.'.self::AUTHOR])
            ->withExists(['messages as unread' => $this->unreadByStaffConstraint()])
            ->when(in_array($unread, ['1', 'true'], true), fn (Builder $query) => $query->whereHas('messages', $this->unreadByStaffConstraint()))
            ->when(in_array($unread, ['0', 'false'], true), fn (Builder $query) => $query->whereDoesntHave('messages', $this->unreadByStaffConstraint()))
            ->orderBy($sort, $order)
            ->when($sort !== 'id', fn (Builder $query) => $query->orderBy('id', $order))
            ->get();
    }

    /** @param  list<UploadedFile>  $files */
    public function postUserMessage(User $user, ?string $body, array $files = []): SupportMessage
    {
        return $this->postMessage($this->findOrCreateThreadForUser($user), $user, false, $body, $files);
    }

    /** @param  list<UploadedFile>  $files */
    public function postStaffMessage(User $admin, SupportThread $thread, ?string $body, array $files = []): SupportMessage
    {
        $message = $this->postMessage($thread, $admin, true, $body, $files);

        if ((int) $thread->user_id !== (int) $admin->id) {
            $this->notifyUserAboutReply($thread, $message);
        }

        return $message;
    }

    /** Сколько ответов поддержки пользователь ещё не видел. */
    public function unreadMessageCountForUser(User $user): int
    {
        $thread = SupportThread::query()->where('user_id', $user->id)->first();
        if (! $thread) {
            return 0;
        }

        return $thread->messages()
            ->where('is_staff', true)
            ->when($thread->user_last_read_at, fn (Builder $query, $readAt) => $query->where('created_at', '>', $readAt))
            ->count();
    }

    public function setStatus(SupportThread $thread, string $status): SupportThread
    {
        if (! in_array($status, [SupportThread::STATUS_OPEN, SupportThread::STATUS_CLOSED], true)) {
            throw ValidationException::withMessages(['status' => ['Некорректный статус']]);
        }

        $thread->update(['status' => $status]);

        return $thread;
    }

    public function setCategory(SupportThread $thread, string $category): SupportThread
    {
        if (! in_array($category, [SupportThread::CATEGORY_INBOX, SupportThread::CATEGORY_SPAM], true)) {
            throw ValidationException::withMessages(['category' => ['Некорректная папка']]);
        }

        $thread->update(['category' => $category]);

        return $thread;
    }

    /** @param  list<UploadedFile>  $files */
    private function postMessage(SupportThread $thread, User $author, bool $isStaff, ?string $body, array $files): SupportMessage
    {
        if (count($files) > self::MAX_FILES) {
            throw ValidationException::withMessages(['files' => ['Можно прикрепить не больше '.self::MAX_FILES.' изображений']]);
        }

        // Текст проверяем до сохранения файлов, чтобы при ошибке на диске не остались скриншоты без сообщения.
        $body = $this->normalizeBody($body, hasFiles: $files !== []);

        $message = SupportMessage::query()->create([
            'thread_id' => $thread->id,
            'user_id' => $author->id,
            'is_staff' => $isStaff,
            'body' => $body,
            'attachment' => $files === []
                ? null
                : ['items' => array_map(fn (UploadedFile $file) => $this->attachments->store($file, (int) $thread->id)->toArray(), $files)],
        ]);

        // Новое сообщение переоткрывает закрытое обращение, а своё сообщение автор уже прочитал.
        $thread->update([
            'status' => SupportThread::STATUS_OPEN,
            'last_message_at' => $message->created_at,
            $isStaff ? 'admin_last_read_at' : 'user_last_read_at' => now(),
        ]);

        return $message->load(self::AUTHOR);
    }

    /**
     * Ответ поддержки видно сразу: событием в открытое приложение (чат поддержки обновится сам,
     * на других экранах — тост и счётчик) и пушем, если приложение закрыто.
     */
    private function notifyUserAboutReply(SupportThread $thread, SupportMessage $message): void
    {
        $userId = (int) $thread->user_id;

        broadcast(new SupportReplyPosted(
            $userId,
            (new SupportMessageResource($message))->resolve(),
            $this->unreadMessageCountForUser($thread->user),
        ));

        $preview = mb_strimwidth(trim($message->body), 0, 140, '…');
        $this->push->sendToUsers([$userId], PushNotificationData::fromArray([
            'title' => 'Поддержка SonetCord',
            'body' => $preview !== '' ? $preview : 'Прислали изображение',
            'url' => '/support',
            'tag' => 'support',
            'kind' => 'support',
        ]));
    }

    private function normalizeBody(?string $body, bool $hasFiles): string
    {
        $body = trim((string) $body);

        if ($body === '' && ! $hasFiles) {
            throw ValidationException::withMessages(['body' => ['Напишите сообщение или прикрепите скриншот']]);
        }

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages(['body' => ['Слишком длинное сообщение (макс. '.self::MAX_BODY_LENGTH.')']]);
        }

        return $body;
    }

    /** Сообщение пользователя пришло после того, как поддержка последний раз открывала переписку. */
    private function unreadByStaffConstraint(): Closure
    {
        return fn (Builder $query) => $query
            ->where('is_staff', false)
            ->where(fn (Builder $query) => $query
                ->whereNull('support_threads.admin_last_read_at')
                ->orWhereColumn('support_messages.created_at', '>', 'support_threads.admin_last_read_at'));
    }
}
