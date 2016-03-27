<?php namespace App\Services;

use Auth;
use DB;
use Utils;
use URL;
use App\Services\BaseService;
use App\Ninja\Repositories\ExpenseRepository;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Client;
use App\Models\Vendor;

class ExpenseService extends BaseService
{
       // Expenses
    protected $expenseRepo;
    protected $datatableService;

    public function __construct(ExpenseRepository $expenseRepo, DatatableService $datatableService)
    {
        $this->expenseRepo = $expenseRepo;
        $this->datatableService = $datatableService;
    }

    protected function getRepo()
    {
        return $this->expenseRepo;
    }

    public function save($data, $checkSubPermissions=false)
    {
        if (isset($data['client_id']) && $data['client_id']) {
            $data['client_id'] = Client::getPrivateId($data['client_id']);
        }

        if (isset($data['vendor_id']) && $data['vendor_id']) {
            $data['vendor_id'] = Vendor::getPrivateId($data['vendor_id']);
        }

        return $this->expenseRepo->save($data, $checkSubPermissions);
    }

    public function getDatatable($search)
    {
        $query = $this->expenseRepo->find($search);

        if(!Utils::hasPermission('view_all')){
            $query->where('expenses.user_id', '=', Auth::user()->id);
        }

        return $this->createDatatable(ENTITY_EXPENSE, $query);
    }

    public function getDatatableVendor($vendorPublicId)
    {
        $query = $this->expenseRepo->findVendor($vendorPublicId);
        return $this->datatableService->createDatatable(ENTITY_EXPENSE,
                                                        $query,
                                                        $this->getDatatableColumnsVendor(ENTITY_EXPENSE,false),
                                                        $this->getDatatableActionsVendor(ENTITY_EXPENSE),
                                                        false);
    }

    protected function getDatatableColumns($entityType, $hideClient)
    {
        return [
            [
                'vendor_name',
                function ($model)
                {
                    if ($model->vendor_public_id) {
                        if(!Vendor::canViewItemByOwner($model->vendor_user_id)){
                            return $model->vendor_name;
                        }
                        
                        return link_to("vendors/{$model->vendor_public_id}", $model->vendor_name)->toHtml();
                    } else {
                        return '';
                    }
                }
            ],
            [
                'client_name',
                function ($model)
                {
                    if ($model->client_public_id) {
                        if(!Client::canViewItemByOwner($model->client_user_id)){
                            return Utils::getClientDisplayName($model);
                        }
                        
                        return link_to("clients/{$model->client_public_id}", Utils::getClientDisplayName($model))->toHtml();
                    } else {
                        return '';
                    }
                }
            ],
            [
                'expense_date',
                function ($model) {
                    if(!Expense::canEditItemByOwner($model->user_id)){
                        return Utils::fromSqlDate($model->expense_date);
                    }
                    
                    return link_to("expenses/{$model->public_id}/edit", Utils::fromSqlDate($model->expense_date))->toHtml();
                }
            ],
            [
                'amount',
                function ($model) {
                    // show both the amount and the converted amount
                    if ($model->exchange_rate != 1) {
                        $converted = round($model->amount * $model->exchange_rate, 2);
                        return Utils::formatMoney($model->amount, $model->expense_currency_id) . ' | ' .
                            Utils::formatMoney($converted, $model->invoice_currency_id);
                    } else {
                        return Utils::formatMoney($model->amount, $model->expense_currency_id);
                    }
                }
            ],
            [
                'public_notes',
                function ($model) {
                    return $model->public_notes != null ? substr($model->public_notes, 0, 100) : '';
                }
            ],
            [
                'expense_status_id',
                function ($model) {
                    return self::getStatusLabel($model->invoice_id, $model->should_be_invoiced);
                }
            ],
        ];
    }

    protected function getDatatableColumnsVendor($entityType, $hideClient)
    {
        return [
            [
                'expense_date',
                function ($model) {
                    return Utils::dateToString($model->expense_date);
                }
            ],
            [
                'amount',
                function ($model) {
                    return Utils::formatMoney($model->amount, false, false);
                }
            ],
            [
                'public_notes',
                function ($model) {
                    return $model->public_notes != null ? $model->public_notes : '';
                }
            ],
            [
                'invoice_id',
                function ($model) {
                    return '';
                }
            ],
        ];
    }

    protected function getDatatableActions($entityType)
    {
        return [
            [
                trans('texts.edit_expense'),
                function ($model) {
                    return URL::to("expenses/{$model->public_id}/edit") ;
                },
                function ($model) {
                    return Expense::canEditItem($model);
                }
            ],
            [
                trans('texts.view_invoice'),
                function ($model) {
                    return URL::to("/invoices/{$model->invoice_public_id}/edit");
                },
                function ($model) {
                    return $model->invoice_public_id && Invoice::canEditItemByOwner($model->invoice_user_id);
                }
            ],
            [
                trans('texts.invoice_expense'),
                function ($model) {
                    return "javascript:invoiceEntity({$model->public_id})";
                },
                function ($model) {
                    return ! $model->invoice_id && (!$model->deleted_at || $model->deleted_at == '0000-00-00') && Invoice::canCreate();
                }
            ],
        ];
    }

    protected function getDatatableActionsVendor($entityType)
    {
        return [];
    }

    private function getStatusLabel($invoiceId, $shouldBeInvoiced)
    {
        if ($invoiceId) {
            $label = trans('texts.invoiced');
            $class = 'success';
        } elseif ($shouldBeInvoiced) {
            $label = trans('texts.pending');
            $class = 'warning';
        } else {
            $label = trans('texts.logged');
            $class = 'primary';
        }

        return "<h4><div class=\"label label-{$class}\">$label</div></h4>";
    }

}
