<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reference links on a task — the spec's `task_links`: url plus label.
     *
     * Not attachments. An attachment is a file this application stores and is answerable for;
     * a link is a string pointing somewhere else. They arrive in different phases and they are
     * different tables on purpose.
     */
    public function up(): void
    {
        Schema::create('task_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();

            // 2048 is the practical ceiling browsers and proxies agree on for a URL; `text`
            // would accept a megabyte of paste into a field the UI renders as one line.
            $table->string('url', 2048);

            // Optional: a link with no label renders as its host, which is better than forcing
            // somebody to retype the URL into a second box.
            $table->string('label')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['task_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_links');
    }
};
