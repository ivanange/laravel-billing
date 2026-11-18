<?php

declare(strict_types=1);

namespace Ivanange\Billing\Filament\Forms;

use Closure;
use Filament\Forms\Components\FileUpload;
use finfo;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Ivanange\Billing\Models\Payment;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The proof-of-payment upload of the record form.
 *
 * Filament would normally move the upload to the disk when the form is
 * submitted and hand back the stored path, but that path is a client-
 * controlled string: anyone could swap it for another file on the disk. So
 * the field keeps the Livewire temporary upload and the file is stored here,
 * under a ULID we generate, only while the payment is being recorded. The
 * stored path never travels through the browser, a failed attempt removes
 * it again, and the temporary upload stays in the form for the retry.
 */
final class ProofUpload
{
    /** Names a stored proof may carry, and an upload's original name must end with. */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    /** Type detected from the file's bytes => extension it is stored under. */
    public const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public const MAX_KILOBYTES = 5120;

    /** Enough bytes for finfo to recognise every allowed type. */
    private const SNIFF_BYTES = 64 * 1024;

    public static function field(Closure $required): FileUpload
    {
        return FileUpload::make('proof')
            ->label(__('billing::billing.ui.record.proof'))
            ->disk(self::diskName())
            ->directory(self::directory())
            ->visibility('private')
            ->acceptedFileTypes(array_keys(self::TYPES))
            ->maxSize(self::MAX_KILOBYTES)
            ->storeFiles(false)
            // Never describe a stored file: a submitted path is not trusted, so
            // it must not get a preview or a temporary URL either.
            ->getUploadedFileUsing(fn (): ?array => null)
            ->required($required);
    }

    /**
     * Store the upload of this request and return its path, or null when no
     * proof was attached.
     */
    public static function store(mixed $state): ?string
    {
        if (blank($state)) {
            return null;
        }

        $extension = $state instanceof TemporaryUploadedFile && $state->exists() ? self::extension($state) : null;

        if ($extension === null) {
            throw new InvalidArgumentException(__('billing::billing.ui.record.invalid_proof'));
        }

        $path = $state->storeAs(self::directory(), Str::ulid().'.'.$extension, ['disk' => self::diskName(), 'visibility' => 'private']);

        if ($path === false) {
            throw new InvalidArgumentException(__('billing::billing.ui.record.invalid_proof'));
        }

        return $path;
    }

    /**
     * The extension to store the upload under, from its content; null when
     * its name, size or content is not an allowed proof.
     */
    private static function extension(TemporaryUploadedFile $file): ?string
    {
        $named = strtolower(pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));

        if (! in_array($named, self::EXTENSIONS, true) || $file->getSize() > self::MAX_KILOBYTES * 1024) {
            return null;
        }

        return self::TYPES[self::detectedType($file)] ?? null;
    }

    /**
     * Read the bytes ourselves: depending on the Livewire version and disk,
     * TemporaryUploadedFile's MIME type can be the one the browser declared
     * (e.g. the content type an S3 upload was given), so an HTML or SVG file
     * could pass as a .png.
     */
    private static function detectedType(TemporaryUploadedFile $file): string
    {
        $stream = $file->readStream();

        if (! is_resource($stream)) {
            return '';
        }

        try {
            $head = stream_get_contents($stream, self::SNIFF_BYTES);
        } finally {
            fclose($stream);
        }

        return is_string($head) && $head !== '' ? (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($head) : '';
    }

    public static function discard(?string $path): void
    {
        if ($path !== null) {
            self::disk()->delete($path);
        }
    }

    /**
     * For a path handed to RecordPaymentForm::draft() directly: accept only a
     * file stored by store() (a ULID name straight inside the proofs
     * directory) that exists and no other payment uses.
     */
    public static function validPath(mixed $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        $directory = preg_quote(self::directory(), '#');
        $extensions = implode('|', self::EXTENSIONS);
        $valid = is_string($path)
            && preg_match('#^'.$directory.'/[0-9A-HJKMNP-TV-Z]{26}\.('.$extensions.')$#', $path) === 1
            && self::disk()->exists($path)
            && ! Payment::query()->where('proof_path', $path)->exists();

        if (! $valid) {
            throw new InvalidArgumentException(__('billing::billing.ui.record.invalid_proof'));
        }

        return $path;
    }

    private static function directory(): string
    {
        return trim((string) config('billing.proofs.directory', 'billing/proofs'), '/');
    }

    private static function diskName(): string
    {
        return (string) config('billing.proofs.disk', 'local');
    }

    private static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }
}
