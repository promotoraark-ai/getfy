<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberLessonLike;
use App\Models\MemberLessonPdfAnnotation;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemberAreaPdfReaderTest extends TestCase
{
    public function test_pdf_proxy_streams_for_pdf_reader_type(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = 'pdfread'.substr(uniqid('', true), -6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);

        $remoteUrl = 'https://r2.example.com/member-area/test/reader.pdf';
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Leitor',
            'position' => 1,
            'type' => MemberLesson::TYPE_PDF_READER,
            'content_url' => '',
            'content_files' => [
                ['url' => $remoteUrl, 'name' => 'reader.pdf'],
            ],
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        Http::fake([
            $remoteUrl => Http::response('%PDF-reader-test', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $res = $this->actingAs($aluno)->get('/m/'.$slug.'/aula/'.$lesson->id.'/pdf/0');

        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('%PDF-reader-test', $res->getContent());
    }

    public function test_put_and_get_pdf_annotations(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = 'pdfann'.substr(uniqid('', true), -6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);

        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Leitor',
            'position' => 1,
            'type' => MemberLesson::TYPE_PDF_READER,
            'content_url' => 'https://r2.example.com/x.pdf',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        $payload = [
            'file_index' => 0,
            'highlights' => [
                [
                    'id' => 'h1',
                    'page' => 1,
                    'color' => 'yellow',
                    'x' => 0.1,
                    'y' => 0.2,
                    'width' => 0.3,
                    'height' => 0.05,
                ],
            ],
        ];

        $this->actingAs($aluno)
            ->putJson('/m/'.$slug.'/aula/'.$lesson->id.'/pdf-annotations', $payload)
            ->assertOk()
            ->assertJson(['success' => true]);

        $annGet = $this->actingAs($aluno)
            ->getJson('/m/'.$slug.'/aula/'.$lesson->id.'/pdf-annotations')
            ->assertOk();

        $byFile = $annGet->json('annotations_by_file');
        $this->assertIsArray($byFile);
        $list = $byFile['0'] ?? $byFile[0] ?? [];
        $this->assertSame('h1', $list[0]['id'] ?? null);

        $this->assertDatabaseHas('member_lesson_pdf_annotations', [
            'user_id' => $aluno->id,
            'member_lesson_id' => $lesson->id,
            'file_index' => 0,
        ]);
    }

    public function test_toggle_lesson_like_updates_count(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = 'pdflk'.substr(uniqid('', true), -6);
        $product = $this->createTestProduct([
            'type' => Product::TYPE_AREA_MEMBROS,
            'checkout_slug' => $slug,
        ]);

        $section = MemberSection::create([
            'product_id' => $product->id,
            'title' => 'Seção',
            'position' => 1,
            'section_type' => 'courses',
        ]);

        $module = MemberModule::create([
            'member_section_id' => $section->id,
            'product_id' => $product->id,
            'title' => 'Módulo',
            'position' => 1,
        ]);

        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Leitor',
            'position' => 1,
            'type' => MemberLesson::TYPE_PDF_READER,
            'content_url' => 'https://r2.example.com/x.pdf',
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        $res = $this->actingAs($aluno)
            ->postJson('/m/'.$slug.'/aula/'.$lesson->id.'/like')
            ->assertOk();

        $this->assertTrue($res->json('liked'));
        $this->assertSame(1, (int) $res->json('likes_count'));

        $lesson->refresh();
        $this->assertSame(1, (int) $lesson->likes_count);
        $this->assertTrue(MemberLessonLike::where('user_id', $aluno->id)->where('member_lesson_id', $lesson->id)->exists());

        $res2 = $this->actingAs($aluno)
            ->postJson('/m/'.$slug.'/aula/'.$lesson->id.'/like')
            ->assertOk();

        $this->assertFalse($res2->json('liked'));
        $this->assertSame(0, (int) $res2->json('likes_count'));

        $lesson->refresh();
        $this->assertSame(0, (int) $lesson->likes_count);
    }
}
