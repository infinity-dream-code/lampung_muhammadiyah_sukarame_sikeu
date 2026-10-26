<?php

namespace App\Http\Controllers\Admin\MasterData;

use App\Http\Controllers\Controller;
use App\Models\mst_tagihan;
use App\Models\ValidationMessage;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MasterTagihanController extends Controller
{
    public string $title = 'Master Data';
    public string $mainTitle = 'Master Tagihan';
    public string $dataTitle = 'Master Tagihan';

    public function index()
    {
        $data['title'] = $this->title;
        $data['mainTitle'] = $this->mainTitle;
        $data['dataTitle'] = $this->dataTitle;
        $data['columnsUrl'] = route('admin.master-data.master-tagihan.get-column');
        $data['datasUrl'] = route('admin.master-data.master-tagihan.get-data');

        return view('admin.master_data.master_tagihan.index', $data);
    }

    public function getColumn()
    {
        return [
            ['data' => null, 'name' => 'no', 'className' => 'text-center', 'columnType' => 'no'],
            ['data' => 'kode', 'name' => 'Kode Akun', 'searchable' => true, 'orderable' => true],
            ['data' => 'tagihan', 'name' => 'Nama Tagihan', 'searchable' => true, 'orderable' => true],
            ['data' => 'isINSTALLMENT_label', 'name' => 'Status Dapat Di Cicil', 'searchable' => false, 'orderable' => true],
        ];
    }

    public function getData(Request $request)
    {
        $draw = $request->get('draw');
        $start = $request->get('start');
        $rowperpage = $request->get('length');

        $columnIndex_arr = $request->get('order', []);
        $columnName_arr = $request->get('columns', []);
        $order_arr = $request->get('order', []);
        $search_arr = $request->get('search', []);
        $searchValue = $search_arr['value'] ?? '';

        $columnName = 'urut';
        $columnSortOrder = 'asc';

        if (!empty($order_arr)) {
            $columnIndex = $columnIndex_arr[0]['column'] ?? null;
            if ($columnIndex !== null && !empty($columnName_arr[$columnIndex]['data']) && $columnName_arr[$columnIndex]['data'] !== 'no') {
                $sortColumn = $columnName_arr[$columnIndex]['data'];
                $columnName = $sortColumn === 'isINSTALLMENT_label' ? 'isINSTALLMENT' : $sortColumn;
                $columnSortOrder = $order_arr[0]['dir'] ?? 'asc';
            }
        }

        mst_tagihan::ensureAkunPenerimaan();

        $totalRecords = mst_tagihan::count();
        $totalRecordswithFilter = mst_tagihan::where(function ($q) use ($searchValue) {
            $q->where('tagihan', 'like', '%' . $searchValue . '%')
                ->orWhere('kode', 'like', '%' . $searchValue . '%');
        })->count();

        $records = mst_tagihan::orderBy($columnName, $columnSortOrder)
            ->where(function ($q) use ($searchValue) {
                $q->where('tagihan', 'like', '%' . $searchValue . '%')
                    ->orWhere('kode', 'like', '%' . $searchValue . '%');
            })
            ->skip($start)
            ->take($rowperpage)
            ->get()
            ->map(function ($item) {
                $item->isINSTALLMENT_label = (int) $item->isINSTALLMENT === 1
                    ? 'BISA DI CICIL'
                    : 'TIDAK BISA DI CICIL';
                return $item;
            })
            ->toArray();

        return response()->json([
            'draw' => intval($draw),
            'recordsTotal' => $totalRecords,
            'recordsFiltered' => $totalRecordswithFilter,
            'data' => $records,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make(
            $request->all(),
            [
                'tagihan' => ['required', 'string', 'max:100'],
                'isINSTALLMENT' => ['required', 'in:0,1'],
            ],
            ValidationMessage::messages(),
            ValidationMessage::attributes()
        );

        if ($validator->fails()) {
            return response()->json(['message' => $validator->errors()->first(), 'errors' => $validator->errors()], 422);
        }

        $exists = mst_tagihan::where('tagihan', $request->tagihan)->first();
        if ($exists) {
            return response()->json(['message' => 'Nama tagihan sudah ada'], 422);
        }

        try {
            DB::connection('DATA_MYSQL')->beginTransaction();

            $nextUrut = (int) mst_tagihan::max('urut') + 1;
            mst_tagihan::ensureAkunPenerimaan();

            mst_tagihan::create([
                'urut' => $nextUrut,
                'tagihan' => strtoupper(trim($request->tagihan)),
                'kode' => mst_tagihan::kodePenerimaan(),
                'isINSTALLMENT' => (int) $request->isINSTALLMENT,
            ]);

            DB::connection('DATA_MYSQL')->commit();
            mst_tagihan::flushInstallmentCache();

            return response()->json(['message' => 'Data ' . $this->mainTitle . ' telah disimpan']);
        } catch (Exception $e) {
            DB::connection('DATA_MYSQL')->rollBack();
            return response()->json(['message' => 'Data ' . $this->mainTitle . ' gagal disimpan', 'error' => $e->getMessage()], 422);
        }
    }
}
