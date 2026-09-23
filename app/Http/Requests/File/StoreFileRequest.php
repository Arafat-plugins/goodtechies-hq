<?php

namespace App\Http\Requests\File;

use App\Exceptions\FileStateException;
use App\Services\FileService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * An upload: one file, against a record that already exists.
 *
 * Every rule about WHAT may be stored comes from FileService, not from a list retyped here.
 * The size limit and the allow-list are constants on the service because the service is also
 * the thing a job or a console command would call, and two lists is one list that can drift —
 * the version where the validator accepts a type the writer then refuses.
 *
 * The closure is where the pairing is checked: `extensions:` and `mimetypes:` on their own
 * would each pass a `.png` whose contents are a PDF, because neither looks at the other. The
 * service's assertion looks at both together, and reporting its message against the field is
 * what turns "the writer would refuse this" into a sentence under the file input.
 *
 * Authorization is NOT here. Who may attach a file to a task, a project or a client is the
 * owning record's own policy, checked by the controller against the resolved owner and again by
 * the service — a Form Request that answered it would be a third copy, and the one furthest
 * from the write.
 */
class StoreFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // `bail` so an oversized or wrong-typed upload produces one sentence, not three.
            // `max:` is stated before the closure so the common refusal gets Laravel's own
            // message, which already names the limit in the right units.
            'file' => [
                'required',
                'bail',
                'file',
                'max:'.FileService::maxKilobytes(),
                $this->acceptable(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file to upload.',
            'file.max' => 'That file is too large. The limit is '
                .round(FileService::MAX_BYTES / 1048576).' MB.',
        ];
    }

    /**
     * The uploaded file, typed, once validation has passed.
     */
    public function upload(): UploadedFile
    {
        /** @var UploadedFile $upload */
        $upload = $this->file('file');

        return $upload;
    }

    /**
     * The service's own "will this be stored at all" rule, reported against the field.
     */
    private function acceptable(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! $value instanceof UploadedFile) {
                $fail('That upload did not arrive intact. Try again.');

                return;
            }

            try {
                FileService::assertAcceptable($value);
            } catch (FileStateException $exception) {
                $fail($exception->getMessage());
            }
        };
    }
}
