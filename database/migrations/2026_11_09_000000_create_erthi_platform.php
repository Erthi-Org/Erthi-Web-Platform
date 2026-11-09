<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('role', 20)->default('viewer'));

        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            foreach (['ar', 'en'] as $locale) {
                $table->string("title_{$locale}");
                $table->text("summary_{$locale}");
            }
            $table->string('repository')->unique();
            $table->string('icon', 24);
            $table->boolean('published')->default(true);
            $table->timestamps();
        });

        Schema::create('content_blocks', function (Blueprint $table): void {
            $table->string('locale', 2);
            $table->string('page', 24);
            $table->string('title');
            $table->text('body');
            $table->primary(['locale', 'page']);
        });

        Schema::create('site_settings', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->text('value');
        });

        Schema::create('submissions', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 16);
            $table->string('name');
            $table->string('email');
            $table->string('topic', 24)->nullable();
            $table->text('message');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at');
            $table->index(['kind', 'created_at']);
        });

        Schema::create('media', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('file')->unique();
            $table->timestamp('created_at');
        });

        DB::table('projects')->insert([
            [
                'title_ar' => 'دليل المسلم', 'title_en' => 'Muslim Guide',
                'summary_ar' => 'مشروع تطبيق إسلامي مفتوح المصدر ضمن مبادرة إرثي.',
                'summary_en' => 'An open-source Islamic app project from Erthi.',
                'repository' => 'Erthi-Org/Muslim-Guide', 'icon' => 'quran',
                'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'title_ar' => 'نور البصيرة', 'title_en' => 'Noor Al-Basirah',
                'summary_ar' => 'مشروع تطبيق إسلامي تعليمي مفتوح المصدر ضمن مبادرة إرثي.',
                'summary_en' => 'An open-source Islamic education app project from Erthi.',
                'repository' => 'Erthi-Org/Noor-Al-Basirah', 'icon' => 'learn',
                'created_at' => now(), 'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        foreach (['media', 'submissions', 'site_settings', 'content_blocks', 'projects'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
