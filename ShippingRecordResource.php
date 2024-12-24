<?php

namespace App\Filament\Resources;

use App\Filament\Exports\ShippingRecordsExporter;
use App\Filament\Resources\ShippingRecordResource\Sorts\TotalProductsSort;
use App\Models\Product;
use App\Models\ShippingChannel;
use App\Models\ShippingRecord;
use App\Models\Shop;
use App\Models\User;
use App\Models\Warehouse;
use Filament\Actions\Exports\Enums\ExportFormat;
use Filament\Actions\Exports\Models\Export;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\ActionSize;
use Filament\Support\Facades\FilamentAsset;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use App\Filament\Resources\ShippingRecordResource\Pages;
use Filament\Forms\Components\Actions\Action;
use Filament\Support\Assets\Js;
use Filament\Support\Assets\Css;
use Illuminate\Database\Eloquent\Model;
use Filament\Tables\Columns\TextColumn;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\QueryBuilder;
use Spatie\QueryBuilder\AllowedFilter;

class ShippingRecordResource extends Resource
{
    protected static ?string $model = ShippingRecord::class;
    protected static ?string $navigationIcon = 'heroicon-s-truck';
    protected static ?string $navigationLabel = '发货记录';
    protected static ?string $modelLabel = '发货记录';
    protected static ?string $navigationGroup = '任务管理';

    public static $statusList = [
        'pending' => '待发货',
        'shipped' => '已发货',
        'received' => '已签收',
        'shelved' => '已上架',
    ];

    protected static string $recordLoadingStrategy = 'lazy';


    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                // 顶部基础信息
                Forms\Components\Section::make()
                    ->schema([
                        // 日期信息行
                        Forms\Components\Grid::make(4)
                            ->schema([
                                Forms\Components\Select::make('operator_id')
                                    ->label('运营人')
                                    ->options(User::query()->pluck('name', 'id'))
                                    ->searchable()
                                    ->default(auth()->id())
                                    ->disabled(fn ($livewire) => !(auth()->user()->hasRole('admin')))
                                    ->dehydrated()
                                    ->required(),
                                Forms\Components\DatePicker::make('shipping_date')
                                    ->label('发货日期*')
                                    ->required(),
                                Forms\Components\DatePicker::make('estimated_arrival_date')
                                    ->label('预估到达日期'),
                                Forms\Components\DatePicker::make('actual_arrival_date')
                                    ->label('实际送达日期'),
                                Forms\Components\Select::make('shop_id')
                                    ->label('所在店铺*')
                                    ->options(Shop::query()->pluck('name', 'id'))
                                    ->required()
                                    ->searchable(),
                                Forms\Components\TextInput::make('delivery_period')
                                    ->label('配送时段')
                                    ->visible(fn ($livewire) => $livewire instanceof Pages\EditShippingRecord),
                                Forms\Components\TextInput::make('forwarder_tracking_number')
                                    ->label('货代单号'),
//                                    ->visible(fn ($livewire) => $livewire instanceof Pages\EditShippingRecord),
                                Forms\Components\Textarea::make('cost_verification')
                                    ->label('费用核对')
                                    ->dehydrated()
                                    ->rows(1)
                                    ->visible(fn ($livewire) => $livewire instanceof Pages\EditShippingRecord),
                                Forms\Components\TextInput::make('notes')
                                    ->label('备注'),
//                                    ->visible(fn ($livewire) => $livewire instanceof Pages\EditShippingRecord),
                            ]),
                    ])
                    ->columnSpan('full'),

                // FBA货件信息（包含包裹列表）
                Forms\Components\Section::make('货件列表')
                    ->schema([
                        Forms\Components\Repeater::make('shipping_records')
                            ->label('')
                            ->live()
                            ->schema([
                                // 第一行：FBA基本信息
                                Forms\Components\Grid::make(18)
                                    ->schema([
                                        Forms\Components\TextInput::make('fba_shipment_number')
                                            ->label('FBA编号')
                                            ->unique('shipping_records', 'fba_shipment_number', ignoreRecord: true)
                                            ->columnSpan(3)
                                            ->required(),
                                        Forms\Components\Select::make('warehouse_id')
                                            ->label('FBA仓库')
                                            ->options(fn () => Warehouse::active()->pluck('fba_code', 'id'))
                                            ->required()
                                            ->columnSpan(2)
                                            ->searchable(),
                                        Forms\Components\Select::make('shipping_channel_id')
                                            ->label('物流渠道')
                                            ->options(ShippingChannel::query()->where('is_active', true)->get()->mapWithKeys(function ($shippingChannel) {
                                                return [
                                                    $shippingChannel->id => "{$shippingChannel->forwarder->name} - {$shippingChannel->name}"
                                                ];
                                            }))
                                            ->required()
                                            ->columnSpan(3)
                                            ->searchable(),
                                        Forms\Components\TextInput::make('tracking_number')
                                            ->columnSpan(2)
                                            ->label('追踪单号'),
                                        Forms\Components\TextInput::make('chargeable_weight')
                                            ->label('计费重量')
                                            ->suffix('kg')
                                            ->columnSpan(2)
                                            ->numeric(),
                                        Forms\Components\TextInput::make('rate')
                                            ->label('运费单价')
                                            ->prefix('¥')
                                            ->columnSpan(2)
                                            ->numeric(),
                                        Forms\Components\TextInput::make('total_cost')
                                            ->label('总费用')
                                            ->columnSpan(2)
                                            ->prefix('¥')
                                            ->numeric(),
                                        Forms\Components\Actions::make([
                                            Action::make('calculate_cost')
                                                ->label('计算总费用')
                                                ->color('primary')
                                                ->size('xs')
                                                ->icon('heroicon-m-calculator')
                                                ->action(function ($get, Forms\Set $set) {
                                                    $weight = $get('chargeable_weight');
                                                    $rate = $get('rate');
                                                    if ($weight && $rate) {
                                                        $set('total_cost', $weight * $rate);
                                                    }
                                                }),
                                        ])->columnSpan(1)
                                            ->extraAttributes([
                                                'style' => 'position:absolute;margin-top: 32px'
                                            ]),
                                    ]),

                                // 修改 packages repeater 的配置
                                Forms\Components\Repeater::make('packages')
                                    ->label('')
                                    ->live()
                                    ->schema([
                                        Forms\Components\Grid::make(12)
                                            ->schema([
                                                Forms\Components\Select::make('product_id')
                                                    ->label('产品名称')
                                                    ->required()
                                                    ->options(function () {
                                                        return Product::query()
                                                            ->where('is_active', true)
                                                            ->get()
                                                            ->mapWithKeys(function ($product) {
                                                                return [
                                                                    $product->id => "{$product->name} - {$product->asin}"
                                                                ];
                                                            });
                                                    })
                                                    ->live()
                                                    ->afterStateUpdated(function (Forms\Set $set, $state) {
                                                        if ($product = Product::find($state)) {
                                                            $set('asin', $product->asin);
                                                        }
                                                    })
                                                    ->columnSpan(3)
                                                    ->preload() // 预加载选项
                                                    ->searchable(),

                                                Forms\Components\TextInput::make('total_quantity')
                                                    ->label('产品总数')
                                                    ->numeric()
                                                    ->required()
                                                    ->columnSpan(2)
                                                    ->minValue(1),
                                                Forms\Components\TextInput::make('shipping_boxes')
                                                    ->label('发货箱数')
                                                    ->numeric()
                                                    ->required()
                                                    ->columnSpan(2)
                                                    ->minValue(1),
                                                Forms\Components\TextInput::make('asin')
                                                    ->label('ASIN')
                                                    ->columnSpan(2)
                                                    ->disabled(),
                                                Forms\Components\TextInput::make('sku')
                                                    ->label('SKU')
                                                    ->columnSpan(2),
                                            ])
                                    ])
                                    ->defaultItems(1) // 默认不显示任何包裹项
                                    ->addActionLabel('添加新产品')
                                    ->deleteAction(
                                        fn (Action $action) => $action
                                            ->color('success')
                                            ->icon('heroicon-m-trash')
                                            ->size('sm')
                                            ->label('删除')
                                            ->extraAttributes([
                                                'style' => 'position: absolute; margin-top: 4.5rem; margin-left: -4.6rem;'
                                            ])
                                    )
                            ])
                            ->cloneable()
                            ->defaultItems(1)
                            ->maxItems(fn ($livewire) =>
                            $livewire instanceof Pages\CreateShippingRecord ? null : 1
                            )
                            ->cloneAction(
                                fn (Action $action) => $action
                                    ->button()
                                    ->label('复制货件列表')
                                    ->color('warning')
                                    ->visible(fn ($livewire) => $livewire instanceof Pages\CreateShippingRecord)
                            )
                            ->addActionLabel('添加新FBA仓库')
                            ->addAction(
                                fn (Action $action) => $action->color('warning')
                                    ->size('md')
                                    ->icon('heroicon-m-plus-circle')
                            )
                            ->columnSpan('full')
                    ]),
            ]);
    }

    protected function boot(): void
    {
        parent::boot();

        $this->skipLivewireValidation();
        $this->skipLivewirePolling();
    }


    public static function table(Table $table): Table
    {

        // 注册资源
        FilamentAsset::register([
            Js::make('copy', asset('js/filament/custom/copy.js')),
            Js::make('clipboard', asset('js/filament/custom/clipboard.min.js')),
            Css::make('custom', asset('css/filament/table-margin-top.css'))
        ]);


        return $table
            ->poll(null)  // 确保这一行存在
            ->persistFiltersInSession(false)
            ->persistSearchInSession(false)
            ->persistSortInSession(false)
            ->modifyQueryUsing(function (Builder $query) {
                // 首先构建 QueryBuilder
                $queryBuilder = QueryBuilder::for($query)
                    ->defaultSort('-created_at')
                    ->allowedSorts([
                        'shipping_date',
                        'created_at',
                        'total_cost',
                        'status',
                        AllowedSort::custom('total_products', new TotalProductsSort),
                    ])
                    ->allowedFilters([
                        AllowedFilter::callback('shipping_date_range', function (Builder $query, $value) {
                            $query->when(
                                $value['from'] ?? null,
                                fn ($q, $date) => $q->whereDate('shipping_date', '>=', $date)
                            )->when(
                                $value['to'] ?? null,
                                fn ($q, $date) => $q->whereDate('shipping_date', '<=', $date)
                            );
                        }),
                        AllowedFilter::exact('shop_id'),
                        AllowedFilter::exact('operator_id'),
                        AllowedFilter::exact('status'),
                        AllowedFilter::callback('product_or_asin', function (Builder $query, $value) {
                            $query->whereHas('packages.product', function ($q) use ($value) {
                                $q->where('name', 'like', "%{$value}%")
                                    ->orWhere('asin', 'like', "%{$value}%");
                            });
                        }),
                    ]);

                // 应用选择和关联
                return $query->select([
                    'shipping_records.id',
                    'shipping_records.operator_id',
                    'shipping_records.shipping_date',
                    'shipping_records.fba_shipment_number',
                    'shipping_records.shop_id',
                    'shipping_records.warehouse_id',
                    'shipping_records.shipping_channel_id',
                    'shipping_records.total_cost',
                    'shipping_records.status',
                    'shipping_records.cost_verification',
                    'shipping_records.notes',
                    'shipping_records.created_at',
                ])->with([
                    'operator:id,name',
                    'shop:id,name',
                    'warehouse:id,fba_code',
                    'shippingChannel:id,name,forwarder_id',
                    'shippingChannel.forwarder:id,name',
                    'packages:id,shipping_record_id,product_id,total_quantity',
                    'packages.product:id,name,asin',
                ])
                    // 获取底层的查询构建器
                    ->whereIn('shipping_records.id', $queryBuilder->get()->pluck('id'));
            })
            // 3. 减少每次请求的数据量
            ->defaultPaginationPageOption(20)
            ->striped()
            // 禁用不需要的功能
            // 禁用实时统计
            ->queryStringIdentifier('shipping-records') // 设置查询字符串标识符
            ->columns([
                TextColumn::make('operator.name')
                    ->label('运营人')
                    ->searchable()
                    ->toggleable()
                    ->disabledClick()
                    ->sortable(),
                // 1. 发货日期
                TextColumn::make('shipping_date')
                    ->label('发货日期')
                    ->date('Y-m-d')
                    ->sortable()
                    ->toggleable()
                    ->disabledClick()
                    ->searchable(),

                // 2. FBA编号
                TextColumn::make('fba_shipment_number')
                    ->label('FBA编号')
                    ->toggleable()
                    ->toggledHiddenByDefault(false)
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->where('fba_shipment_number', 'like', "%{$search}%");
                    })->disabledClick()
                    ->badge()
                    ->formatStateUsing(function (Model $record): string {
                        return view('components.copyable-badge', [
                            'text' => $record->fba_shipment_number,
                            'label' => 'FBA编号已复制'
                        ])->render();
                    })
                    ->html(),

                // 3. 所在店铺
                TextColumn::make('shop.name')
                    ->label('店铺')
                    ->searchable()
                    ->toggleable()
                    ->disabledClick()
                    ->sortable()
                    ->formatStateUsing(function (Model $record): string {
                        return view('components.copyable-badge', [
                            'text' => $record->shop->name,
                            'label' => '店铺名称已复制'
                        ])->render();
                    })
                    ->html(),

                TextColumn::make('product_name')
                    ->label('品名')
                    ->tooltip(function (Model $record) {
                        return $record->packages
                            ->map(fn($package) => $package->product->name)
                            ->unique()
                            ->values()
                            ->join("\r\n");
                    })
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('packages.product', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%");
                        });
                    })
                    ->badge()
                    ->color('info')
                    ->toggleable()
                    ->toggledHiddenByDefault(false)
                    ->disableClick()  // 添加这行来禁用默认点击行为
                    ->state(function (Model $record) {
                        $products = $record->packages
                            ->pluck('product.name')
                            ->values();

                        return view('filament.resources.shipping-record-resource.modals.detail', [
                            'displayText' => $products->count() >= 2 ? "{$products->take(2)->join(', ')} ...等{$products->count()}个" : ($products->join(', ') == "" ? "无" : $products->join(', ')),
                            'record' => $record,
                        ])->render();
                    })
                    ->html(),



                TextColumn::make('total_products')
                    ->label('总数')
                    ->badge()
                    ->color('success')
                    ->state(function (Model $record) {
                        return $record->packages->sum('total_quantity');
                    })
                    ->alignCenter()
                    ->disabledClick()
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderBy(
                            $query->newQuery()
                                ->selectRaw('SUM(total_quantity)')
                                ->from('packages')
                                ->whereColumn('shipping_records.id', 'packages.shipping_record_id'),
                            $direction
                        );
                    })
                    ->toggleable(),
                // 5. FBA仓库
                TextColumn::make('warehouses_summary')
                    ->label('FBA仓库')
                    ->toggleable()
                    ->toggledHiddenByDefault(false)// 默认显示
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('warehouse', function ($query) use ($search) {
                            $query->where('fba_code', 'like', "%{$search}%");
                        });
                    })
                    ->state(function (Model $record) {
                        return $record->warehouse->fba_code;
                    })
                    ->badge()
                    ->color('success')
                    ->formatStateUsing(function (Model $record): string {
                        return view('components.copyable-badge', [
                            'text' => $record->warehouse->fba_code,
                            'label' => 'FBA仓库已复制'
                        ])->render();
                    })->disabledClick()
                    ->html(),

                TextColumn::make('shipping_channels_summary')
                    ->label('物流渠道')
                    ->toggleable()
                    ->toggledHiddenByDefault(false)// 默认显示
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        return $query->whereHas('shippingChannel', function ($query) use ($search) {
                            $query->where('name', 'like', "%{$search}%")
                                ->orWhereHas('forwarder', function ($query) use ($search) {
                                    $query->where('name', 'like', "%{$search}%");
                                });
                        });
                    })
                    ->state(function (Model $record) {
                        $channels = $record->shippingChannel->forwarder->name . ' - ' . $record->shippingChannel->name;
                        return $channels;
                    })
                    ->badge()
                    ->color('warning')
                    ->toggleable()
                    ->disableClick()  // 添加这行来禁用默认点击行为
                    ->state(function (Model $record) {
                        $products = $record->packages
                            ->pluck('product.name')
                            ->values();


                        return view('filament.resources.shipping-record-resource.modals.detail', [
                            'displayText' => $products->count() >= 2 ? "{$products->take(2)->join(', ')} ...等{$products->count()}个" : ($products->join(', ') == "" ? "无" : $products->join(', ')),
                            'record' => $record,
                        ])->render();
                    })
                    ->badge()
                    ->color('info')
                    ->html(),

                // 7. 物流费用
                TextColumn::make('total_cost')
                    ->label('物流费用')
                    ->prefix('¥')
                    ->numeric( 2)
                    ->disabledClick()
                    ->state(fn (Model $record) => number_format($record->total_cost ?? "0", 2))
                    ->toggleable()
                    ->sortable(),

                // 8. 费用核对
                TextColumn::make('cost_verification')
                    ->label('费用核对')
                    ->searchable()
                    ->toggleable()
                    ->wrap()
                    ->action(
                        Tables\Actions\Action::make('cost_verification')
                            ->form([
                                Forms\Components\Textarea::make('cost_verification')
                                    ->label('费用核对')
                                    ->rows(3),
                            ])
                            ->fillForm(fn (Model $record): array => [
                                'cost_verification' => $record->cost_verification,
                            ])
                            ->action(function (Model $record, array $data): void {
                                $record->update([
                                    'cost_verification' => $data['cost_verification']
                                ]);
                            })
                            ->modalHeading('编辑费用核对信息')
                    ),

                TextColumn::make('status')
                    ->label('物流状态')
                    ->badge()
                    ->default("pending")
                    ->formatStateUsing(fn (string $state): string => [
                        'pending' => '待发货',
                        'shipped' => '已发货',
                        'received' => '已签收',
                        'shelved' => '已上架',
                    ][$state])
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'danger',
                        'shipped' => 'warning',
                        'received' => 'info',
                        'shelved' => 'success',
                    })
                    ->action(
                        Tables\Actions\Action::make('changeStatus')
                            ->modalWidth('xs') // 减小模态框尺寸
                            ->size(ActionSize::Small) // 使用小号按钮
                            ->requiresConfirmation()
                            ->modalHeading('修改状态')
                            ->form([
                                Forms\Components\Select::make('status')
                                    ->label('状态')
                                    ->options([
                                        'pending' => '待发货',
                                        'shipped' => '已发货',
                                        'received' => '已签收',
                                        'shelved' => '已上架',
                                    ])
                                    ->default(fn (Model $record): string => $record->status ?? "pending")
                                    ->reactive()
                                    ->preload() // 预加载选项
                            ])
                            ->action(function (Model $record, array $data): void {
                                // 检查状态是否真的改变了
                                if ($record->status === $data['status']) {
                                    return;
                                }

                                // 使用任务队列来处理更新
                                dispatch(function () use ($record, $data) {
                                    $record->update([
                                        'status' => $data['status']
                                    ]);
                                })->afterCommit();

                                Notification::make()
                                    ->title('物流状态已更新')
                                    ->success()
                                    ->send();
                            })
                            // 优化权限检查
                            ->visible(fn (Model $record): bool =>
                            cache()->remember(
                                "shipping_record_{$record->id}_can_edit",
                                now()->addMinutes(5),
                                fn () => auth()->user()->id == $record->operator_id ||
                                    auth()->user()->hasRole('admin') ||
                                    auth()->user()->hasRole('仓管')
                            )
                            )
                            ->modalCloseButton()
                            ->closeModalByClickingAway(false)
                    )->toggleable()
                    ->sortable(),

                TextColumn::make('delivery_duration')
                    ->label('实际时效')
                    ->badge()
                    ->color('info')
                    ->state(function (Model $record) {
                        if ($record->actual_arrival_date && $record->shipping_date) {
                            return $record->shipping_date->diffInDays($record->actual_arrival_date, false)." 天";
                        }
                        return "未送达";
                    })->disabledClick()
                    ->numeric()
                    ->sortable()
                    ->toggleable(),

                // 9. 备注
                TextColumn::make('notes')
                    ->label('备注')
                    ->searchable()
                    ->limit(14)
                    ->action(
                        Tables\Actions\Action::make('notes')
                            ->form([
                                Forms\Components\Textarea::make('notes')
                                    ->label('备注')
                                    ->required()
                                    ->rows(3),
                            ])
                            ->fillForm(fn (Model $record): array => [
                                'notes' => $record->notes,
                            ])
                            ->action(function (Model $record, array $data): void {
                                $record->update([
                                    'notes' => $data['notes']
                                ]);
                            })
                            ->modalHeading('编辑备注')
                    )->state(fn (Model $record) => $record->notes ?? '无')
                    ->toggledHiddenByDefault()
                    ->toggleable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('创建时间')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable()
                    ->toggleable()
                    ->disabledClick()
                    ->toggledHiddenByDefault()
            ])
            ->filters([
                // 时间区间筛选
                Filter::make('shipping_date_range')
                    ->form([
                        Forms\Components\Grid::make(2)  // 创建2列的网格
                        ->schema([
                            Forms\Components\DatePicker::make('from')
                                ->label('开始日期'),
                            Forms\Components\DatePicker::make('to')
                                ->label('结束日期'),
                        ]),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date): Builder => $query->whereDate('shipping_date', '>=', $date),
                            )
                            ->when(
                                $data['to'],
                                fn (Builder $query, $date): Builder => $query->whereDate('shipping_date', '<=', $date),
                            );
                    }) ->columnSpan([
                        'default' => 3,
                        'sm' => 3,
                    ]),

                // 店铺筛选
                SelectFilter::make('shop_id')
                    ->label('店铺')
                    ->options(Shop::query()->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->columnSpan([
                        'default' => 2,
                        'sm' => 2,
                    ]),

                // 品名/ASIN筛选
                Filter::make('product_or_asin')
                    ->form([
                        Forms\Components\TextInput::make('search')
                            ->label('品名/ASIN')
                            ->placeholder('输入品名或ASIN搜索')
                    ])
                    ->columnSpan([
                        'default' => 2,
                        'sm' => 2,
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            $data['search'],
                            fn (Builder $query, $search): Builder =>
                            $query->whereHas('packages.product', function ($query) use ($search) {
                                $query->where('name', 'like', "%{$search}%")
                                    ->orWhere('asin', 'like', "%{$search}%");
                            })
                        );
                    }),


                // 物流状态筛选
                SelectFilter::make('status')
                    ->label('物流状态')
                    ->options([
                        'pending' => '待发货',
                        'shipped' => '已发货',
                        'received' => '已签收',
                        'shelved' => '已上架',
                    ])->preload()
                    ->columnSpan([
                        'default' => 2,
                        'sm' => 2,
                    ]),

                SelectFilter::make('operator_id')
                    ->label('运营人')
                    ->options(User::query()->pluck('name', 'id'))
                    ->searchable()
                    ->preload()
                    ->columnSpan([
                        'default' => 2,
                        'sm' => 2,
                    ]),

            ], layout: Tables\Enums\FiltersLayout::AboveContent)
            ->filtersFormColumns(12)
            // 默认展开筛选
            ->defaultSort('created_at', 'desc')
            ->striped()
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\ExportBulkAction::make()
                    ->exporter(ShippingRecordsExporter::class)
                    ->fileName(fn (Export $export) => '发货记录_' . now()->format('Y_m_d_His') . '.xlsx')
                    ->formats([
                        ExportFormat::Xlsx,
                    ]),
                Tables\Actions\DeleteBulkAction::make(),
            ])
            ->paginated([10, 20, 50, 100])
            ->contentFooter(function (Table $table) {
                $query = $table->getLivewire()->getFilteredTableQuery();

                $totalWeight = $query->sum('chargeable_weight');
                $totalCost = $query->sum('total_cost');

                // 计算总数
                $totalQuantity = $query->withSum('packages', 'total_quantity')->get()->sum('packages_sum_total_quantity');

                return view('filament.resources.shipping-record-resource.footer', [
                    'totalWeight' => number_format($totalWeight, 2),
                    'totalCost' => number_format($totalCost, 2),
                    'totalQuantity' => number_format($totalQuantity, 0) // 新增总数
                ]);
            })
            ->defaultPaginationPageOption(50);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShippingRecords::route('/'),
            'create' => Pages\CreateShippingRecord::route('/create'),
            'edit' => Pages\EditShippingRecord::route('/{record}/edit'),
        ];
    }

}


