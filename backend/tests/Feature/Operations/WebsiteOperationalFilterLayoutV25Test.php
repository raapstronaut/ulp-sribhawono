<?php

namespace Tests\Feature\Operations;

use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;

/** V2.5 UI-only: shared filter regions and unchanged GET field contract. */
class WebsiteOperationalFilterLayoutV25Test extends OperationsTestCase
{
    public function test_four_operational_filters_keep_fields_and_separate_actions(): void
    {
        $pages = [
            'admin.work-orders.index' => ['q', 'status', 'posko_id', 'shift_code', 'work_date'],
            'admin.row.index' => ['q', 'work_type', 'status', 'penyulang_id', 'work_date'],
            'admin.harsus.index' => ['q', 'source', 'status', 'penyulang_id', 'work_date'],
            'admin.inspeksi.index' => ['q', 'field_team_id', 'penyulang_id', 'result', 'date_from', 'date_to'],
        ];

        foreach ($pages as $routeName => $expectedNames) {
            $url = route($routeName);
            $html = $this->actingAs($this->admin)->get($url)->assertOk()->getContent();
            $doc = new DOMDocument();
            $this->assertTrue($doc->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING));
            $xpath = new DOMXPath($doc);
            $forms = $xpath->query('//form[contains(concat(" ", normalize-space(@class), " "), " pln-operations-filter ")]');
            $this->assertSame(1, $forms->length, $routeName);
            $form = $forms->item(0);
            $this->assertSame('GET', strtoupper($form->getAttribute('method')));
            $this->assertSame($url, $form->getAttribute('action'));
            $headings = $xpath->query('./h3[contains(concat(" ", normalize-space(@class), " "), " pln-operations-filter-title ")]', $form);
            $fields = $xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " pln-operations-filter-fields ")]', $form);
            $actions = $xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " pln-operations-filter-actions ")]', $form);
            $this->assertSame(1, $headings->length);
            $this->assertSame(1, $fields->length);
            $this->assertSame(1, $actions->length);
            $controls = $xpath->query('.//input[@name] | .//select[@name]', $form);
            $names = [];
            foreach ($controls as $control) {
                $names[] = $control->getAttribute('name');
            }
            $this->assertSame($expectedNames, $names, $routeName);
            $this->assertSame(count($expectedNames), $xpath->query('./label/input[@name] | ./label/select[@name]', $fields->item(0))->length);
            $buttons = $xpath->query('./button[@type="submit"]', $actions->item(0));
            $links = $xpath->query('./a', $actions->item(0));
            $this->assertSame(1, $buttons->length);
            $this->assertSame(1, $links->length);
            $this->assertSame('Search', trim($buttons->item(0)->textContent));
            $this->assertSame('Reset', trim($links->item(0)->textContent));
            $this->assertSame($url, $links->item(0)->getAttribute('href'));
        }

        $this->assertSame(0, DB::table('work_orders')->count());
        $this->assertSame(0, DB::table('inspection_findings')->count());
    }
}
