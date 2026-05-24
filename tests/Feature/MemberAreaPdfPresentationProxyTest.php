<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureInstalled;
use App\Models\MemberLesson;
use App\Models\MemberModule;
use App\Models\MemberSection;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemberAreaPdfPresentationProxyTest extends TestCase
{
    public function test_pdf_proxy_streams_same_origin_for_pdf_presentation(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = 'pdfpx'.substr(uniqid('', true), -6);
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

        $remoteUrl = 'https://r2.example.com/member-area/test/pres.pdf';
        $lesson = MemberLesson::create([
            'member_module_id' => $module->id,
            'product_id' => $product->id,
            'title' => 'Apresentação',
            'position' => 1,
            'type' => MemberLesson::TYPE_PDF_PRESENTATION,
            'content_url' => '',
            'content_files' => [
                ['url' => $remoteUrl, 'name' => 'pres.pdf'],
            ],
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        Http::fake([
            $remoteUrl => Http::response('%PDF-1.4 proxy-test', 200, ['Content-Type' => 'application/pdf']),
        ]);

        $res = $this->actingAs($aluno)->get('/m/'.$slug.'/aula/'.$lesson->id.'/pdf/0');

        $res->assertOk();
        $res->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('%PDF-1.4', $res->getContent());
        Http::assertSent(fn ($request) => $request->url() === $remoteUrl);
    }

    public function test_pdf_proxy_returns_404_for_wrong_file_index(): void
    {
        $this->withoutMiddleware(EnsureInstalled::class);

        $slug = 'pdfpx'.substr(uniqid('', true), -6);
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
            'title' => 'Apresentação',
            'position' => 1,
            'type' => MemberLesson::TYPE_PDF_PRESENTATION,
            'content_url' => '',
            'content_files' => [
                ['url' => 'https://r2.example.com/a.pdf', 'name' => 'a.pdf'],
            ],
        ]);

        $aluno = User::factory()->create([
            'role' => User::ROLE_ALUNO,
            'tenant_id' => 1,
        ]);
        $product->users()->attach($aluno->id);

        $this->actingAs($aluno)->get('/m/'.$slug.'/aula/'.$lesson->id.'/pdf/1')->assertNotFound();
    }
}
