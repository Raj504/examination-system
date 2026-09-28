<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\Mark;
use App\Models\MarkImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Walks through the whole process using the web pages, like a user would. */
class WebPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_flow_through_the_web_pages(): void
    {
        Storage::fake('local');

        // Setup page: programme, course, students
        $this->get('/setup')->assertOk()->assertSee('Programmes');
        $this->post('/setup/programmes', ['code' => 'btech', 'name' => 'B.Tech'])->assertSessionHas('success');
        $this->post('/setup/courses', ['programme_code' => 'BTECH', 'code' => 'cs101', 'title' => 'Programming', 'credits' => 4])->assertSessionHas('success');
        $this->post('/setup/students', ['students' => "S001, Asha Rao, BTECH\nS002, Ravi Kumar, btech"])->assertSessionHas('success', '2 student(s) saved.');
        $this->post('/setup/students', ['students' => 'S003, No Programme, XYZ'])->assertSessionHas('error');

        // Examination with one course
        $this->travelTo('2026-09-26'); // academic year 2026-27
        $this->get('/')->assertOk()->assertSee('<option value="2026-27" selected>', false);
        $this->post('/examinations', ['code' => 'bad', 'name' => 'x', 'academic_year' => '1999-00'])->assertSessionHasErrors('academic_year');
        $this->post('/examinations', ['code' => 'sem1', 'name' => 'Semester 1', 'academic_year' => '2026-27'])->assertRedirect();
        $exam = Examination::sole();
        $page = "/examinations/{$exam->id}";

        $this->get($page)->assertOk()->assertSee('Add the courses of this exam');
        $this->post("$page/courses", ['course_code' => 'CS101', 'pass_percentage' => 40, 'components' => [
            ['code' => 'MID', 'name' => '', 'max_marks' => 30, 'weight' => 30],
            ['code' => 'END', 'name' => 'End', 'max_marks' => 70, 'weight' => 80],
        ]])->assertSessionHas('error'); // weights add up to 110
        $this->post("$page/courses", ['course_code' => 'CS101', 'pass_percentage' => 40, 'components' => [
            ['code' => 'MID', 'name' => '', 'max_marks' => 30, 'weight' => 30],
            ['code' => '', 'name' => '', 'max_marks' => '', 'weight' => ''], // empty row: ignored
            ['code' => 'END', 'name' => 'End term', 'max_marks' => 70, 'weight' => 70],
        ]])->assertSessionHas('success');
        $this->post("$page/courses", ['course_code' => 'CS101', 'pass_percentage' => 40, 'components' => [
            ['code' => 'MID', 'name' => '', 'max_marks' => '', 'weight' => 100],
        ]])->assertSessionHasErrors('components.0.max_marks');

        $this->post("$page/enroll", ['course_code' => 'CS101', 'registration_nos' => 's001 S002 S999'])
            ->assertSessionHas('success', '2 enrolled, 0 were already enrolled.')
            ->assertSessionHas('error'); // S999 does not exist

        $this->post("$page/status", ['action' => 'open'])->assertSessionHas('success');
        $this->get($page)->assertOk()->assertSee('Lock marks')->assertSee('upload many marks from a CSV file');

        // Marks: one by hand, the rest by CSV
        $this->get($page)->assertSee('CS101|MID', false);
        $this->post("$page/marks", ['registration_no' => 'S001', 'component' => 'CS101|MID', 'marks' => '25'])->assertSessionHas('success');
        $this->post("$page/marks", ['registration_no' => 'S001', 'component' => 'CS101|MID', 'marks' => '26'])
            ->assertSessionHas('error'); // already exists: must use Edit

        $csv = "registration_no,course_code,component_code,marks\nS001,CS101,END,60\nS002,CS101,MID,AB\nS002,CS101,END,50\nS002,CS101,END,99\n";
        $this->post("$page/uploads", ['file' => UploadedFile::fake()->createWithContent('marks.csv', $csv)])->assertSessionHas('success');
        $import = MarkImport::sole();
        $this->assertSame(MarkImport::COMPLETED_WITH_ERRORS, $import->status); // the last row is a duplicate
        $this->get("$page/uploads/{$import->id}")->assertOk()->assertSee('duplicate_row');

        // Edit a mark, and a stale edit is refused
        $mark = Mark::where('version', 1)->first();
        $this->put("$page/marks/{$mark->id}", ['marks' => '28', 'version' => 1])->assertRedirect($page);
        $this->put("$page/marks/{$mark->id}", ['marks' => '29', 'version' => 1])
            ->assertRedirect("$page/marks/{$mark->id}/edit")
            ->assertSessionHas('error');
        $this->get("$page/marks/{$mark->id}/edit")->assertOk()->assertSee('value="2"', false);

        // Lock, process, publish
        $this->post("$page/status", ['action' => 'lock'])->assertSessionHas('success');
        $this->post("$page/status", ['action' => 'process'])->assertSessionHas('success');
        $this->assertSame(Examination::RESULTS_READY, $exam->refresh()->status);
        $this->get($page)->assertOk()->assertSee('Publish results')->assertSee('S001');
        $this->get("$page/results/S001")->assertOk()->assertSee('SGPA');

        // Students see nothing until publication
        $this->get('/results?exam=SEM1&reg=S001')->assertOk()->assertSee('No published result');
        $this->post("$page/status", ['action' => 'publish'])->assertSessionHas('success');
        $this->get('/results?exam=sem1&reg=s001')->assertOk()->assertSee('Asha Rao')->assertSee('SGPA');
    }

    public function test_api_docs_page_loads_the_openapi_file(): void
    {
        $this->get('/docs')->assertOk()->assertSee('/openapi.yaml');
        $this->assertFileExists(public_path('openapi.yaml'));
    }

    public function test_business_rule_errors_are_shown_on_the_page(): void
    {
        $exam = Examination::create(['code' => 'EMPTY', 'name' => 'Empty', 'academic_year' => '2026']);

        $this->from("/examinations/{$exam->id}")
            ->post("/examinations/{$exam->id}/status", ['action' => 'open'])
            ->assertRedirect("/examinations/{$exam->id}")
            ->assertSessionHas('error', 'Examination has no courses.');
    }
}
