<?php

namespace App\Models;

use App\Enums\BeyondFreeZoneDelivery;
use App\Enums\BillingPlan;
use App\Enums\PosType;
use App\Models\Concerns\HasGeneratedLocation;
use App\Services\Billing\BillingCalculator;
use App\Support\TestAccess;
use App\Support\WorkHours;
use Carbon\CarbonImmutable;
use Database\Factories\RestaurantFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class Restaurant extends Model
{
    /** @use HasFactory<RestaurantFactory> */
    use HasFactory;

    use HasGeneratedLocation;

    protected $fillable = [
        'name',
        'district_id',
        'lat',
        'lng',
        'phone',
        'notify_chat_id',
        'logo_url',
        'avg_prep_time_min',
        'cached_average_rating',
        'cached_ratings_count',
        'delivery_radius_km',
        'free_delivery_radius_km',
        'price_per_km',
        'beyond_free_zone_delivery',
        'min_order_amount',
        'delivery_fee',
        'is_open',
        'is_test',
        'billing_plan',
        'commission_rate_bp',
        'monthly_fee',
        'billing_started_at',
        'work_hours',
        'pos_type',
        'printer_host',
        'printer_port',
        'pos_credentials',
        'print_agent_token',
        'receipt_paper_mm',
        'receipt_codepage',
        'receipt_columns',
    ];

    /** `location` — PostGIS tomonidan lat/lng dan hisoblanadi, qo'lda yozilmaydi. */
    protected $hidden = [
        'pos_credentials',
        'notify_chat_id',
        'print_agent_token',
        'location',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'avg_prep_time_min' => 'integer',
            'cached_average_rating' => 'float',
            'cached_ratings_count' => 'integer',
            'delivery_radius_km' => 'float',
            'free_delivery_radius_km' => 'float',
            'beyond_free_zone_delivery' => BeyondFreeZoneDelivery::class,
            'billing_plan' => BillingPlan::class,
            'commission_rate_bp' => 'integer',
            'monthly_fee' => 'integer',
            'billing_started_at' => 'date',
            'price_per_km' => 'integer',
            'min_order_amount' => 'integer',
            'delivery_fee' => 'integer',
            'is_open' => 'boolean',
            'is_test' => 'boolean',
            'work_hours' => 'array',
            'pos_type' => PosType::class,
            'pos_credentials' => 'encrypted:array',
            'printer_port' => 'integer',
            'print_agent_last_seen_at' => 'datetime',
            'receipt_paper_mm' => 'integer',
            'receipt_codepage' => 'integer',
            'receipt_columns' => 'integer',
        ];
    }

    /** @return BelongsTo<District, Restaurant> */
    public function district(): BelongsTo
    {
        return $this->belongsTo(District::class);
    }

    /** @return HasMany<Category> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasManyThrough<Product> */
    public function products(): HasManyThrough
    {
        return $this->hasManyThrough(Product::class, Category::class);
    }

    /** Tarif tarixi (davr uchun tarifning manbasi); billing_* ustunlari faqat "joriy tarif". @return HasMany<RestaurantBillingPlan> */
    public function billingPlans(): HasMany
    {
        return $this->hasMany(RestaurantBillingPlan::class)->orderBy('effective_from');
    }

    /**
     * JORIY oy uchun amaldagi tarif yozuvi (yuklangan `billingPlans` bo'yicha, qo'shimcha so'rovsiz).
     * Hisob-kitob manbasi emas — ko'rsatish uchun.
     */
    public function currentBillingEntry(): ?RestaurantBillingPlan
    {
        $month = BillingCalculator::monthStart(CarbonImmutable::now(BillingCalculator::timezone()))->format('Y-m-d');

        return $this->billingPlans->filter(fn (RestaurantBillingPlan $e): bool => $e->effective_from->format('Y-m-d') <= $month)->last();
    }

    /** Kelajakda (keyingi oylardan) amalga kiradigan eng yaqin tarif yozuvi. */
    public function upcomingBillingEntry(): ?RestaurantBillingPlan
    {
        $month = BillingCalculator::monthStart(CarbonImmutable::now(BillingCalculator::timezone()))->format('Y-m-d');

        return $this->billingPlans->first(fn (RestaurantBillingPlan $e): bool => $e->effective_from->format('Y-m-d') > $month);
    }

    /** @return HasMany<RestaurantInvoice> */
    public function invoices(): HasMany
    {
        return $this->hasMany(RestaurantInvoice::class);
    }

    /** @return HasMany<Order> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Print agenti SOZLANGANmi: pos_type = escpos VA print_agent_token bor. Faqat shunday restoranda chek
     * kutiladi — agent o'chiq banneri, signal, «Chek chiqmadi» belgisi va admin jadvali faqat ular uchun.
     * Printeri yo'q (yoki token berilmagan) restoranda bularning hech biri chiqmaydi.
     */
    public function usesPrintAgent(): bool
    {
        return $this->pos_type === PosType::EscPos && filled($this->print_agent_token);
    }

    /** Print agenti sozlangan restoranlar (usesPrintAgent bilan bir xil shart). */
    public function scopeWithPrintAgent(Builder $query): Builder
    {
        return $query->where('restaurants.pos_type', PosType::EscPos->value)
            ->whereNotNull('restaurants.print_agent_token')
            ->where('restaurants.print_agent_token', '!=', '');
    }

    /** Chek qog'ozi kengligi, mm (58 yoki 80; standart 80). */
    public function receiptPaperMm(): int
    {
        return (int) ($this->receipt_paper_mm ?? config('print.default_paper_mm'));
    }

    /**
     * Chek qatoridagi belgilar soni. `receipt_columns` berilgan bo'lsa shu (32 / 42 / 48 — masalan 80 mm
     * Xprinter XP-80T Font A'da 48); aks holda qog'oz kengligidan: 58 mm — 32, 80 mm — 42 (config print.paper_columns).
     */
    public function receiptColumns(): int
    {
        $columns = (array) config('print.paper_columns');
        $explicit = (int) $this->receipt_columns;

        if (in_array($explicit, (array) config('print.columns_allowed'), true)) {
            return $explicit;
        }

        return (int) ($columns[$this->receiptPaperMm()] ?? $columns[config('print.default_paper_mm')]);
    }

    /** ESC t n — printer kodlash jadvali raqami (standart 16 = WPC1252, Xprinter/Epson-mos). */
    public function receiptCodepage(): int
    {
        return (int) ($this->receipt_codepage ?? config('print.default_codepage'));
    }

    /** Agent oxirgi heartbeat'dan beri `print.agent_offline_after_seconds` dan kam jim bo'lgan. */
    public function printAgentOnline(): bool
    {
        return $this->print_agent_last_seen_at !== null
            && $this->print_agent_last_seen_at->gte(now()->subSeconds((int) config('print.agent_offline_after_seconds')));
    }

    /** `online` | `offline` (oldin ulangan, hozir jim) | `never` (hech qachon ulanmagan). */
    public function printAgentStatus(): string
    {
        return match (true) {
            $this->print_agent_last_seen_at === null => 'never',
            $this->printAgentOnline() => 'online',
            default => 'offline',
        };
    }

    /** @return BelongsToMany<PromoCode> Shu restoranda ishlaydigan kodlar (admin tanlagan). */
    public function promoCodes(): BelongsToMany
    {
        return $this->belongsToMany(PromoCode::class, 'promo_code_restaurant');
    }

    /** @return HasMany<Staff> */
    public function staff(): HasMany
    {
        return $this->hasMany(Staff::class);
    }

    /**
     * Berilgan nuqtadan yetkazish radiusi ichidagi restoranlar.
     * `location` ustuni + GIST indeks (restaurants_location_gist) ishlatiladi.
     */
    public function scopeDeliversTo(Builder $query, float $lat, float $lng): Builder
    {
        return $query->whereRaw(
            'ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, delivery_radius_km * 1000)',
            [$lng, $lat],
        );
    }

    /**
     * Berilgan nuqtadan FIKS radius (km) ichidagi restoranlar — restoranning
     * o'z `delivery_radius_km`iga bog'liq emas. Olib ketish (pickup) uchun:
     * u yerda mijoz o'zi boradi, restoran radiusi cheklov emas
     * (config('geo.pickup_radius_km')).
     */
    public function scopeWithinKm(Builder $query, float $lat, float $lng, float $radiusKm): Builder
    {
        return $query->whereRaw(
            'ST_DWithin(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography, ?)',
            [$lng, $lat, $radiusKm * 1000],
        );
    }

    /** Tanlangan nuqtadan masofani (km) hisoblab `distance_km` ustunini qo'shadi. */
    public function scopeWithDistanceKm(Builder $query, float $lat, float $lng): Builder
    {
        if (empty($query->getQuery()->columns)) {
            $query->select($query->qualifyColumn('*'));
        }

        return $query->selectRaw(
            'ST_Distance(location, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) / 1000 AS distance_km',
            [$lng, $lat],
        );
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('is_open', true);
    }

    /**
     * Mijozga ko'rinadigan restoranlar: test restoran (is_test) faqat
     * TEST_TELEGRAM_IDS dagi hisoblarga (TestAccess). Ro'yxat bo'sh bo'lsa —
     * hech kimga.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return TestAccess::isTester($user)
            ? $query
            : $query->where($this->qualifyColumn('is_test'), false);
    }

    public function isVisibleTo(?User $user): bool
    {
        return ! $this->is_test || TestAccess::isTester($user);
    }

    /**
     * Test restoranlar id'lari — `whereNotIn(..., Restaurant::testIdsQuery())`
     * uchun subquery. DB::table — Eloquent global scope'larini chetlab.
     */
    public static function testIdsQuery(): QueryBuilder
    {
        return DB::table('restaurants')->select('id')->where('is_test', true);
    }

    public function workHours(): WorkHours
    {
        return WorkHours::from($this->work_hours);
    }

    /** is_open bayrog'i VA joriy vaqt (Asia/Tashkent) work_hours ichida. */
    public function isOpenNow(): bool
    {
        return $this->isOpenAt(CarbonImmutable::now());
    }

    /** isOpenNow() — berilgan lahza uchun (ish vaqti Asia/Tashkent bo'yicha solishtiriladi). */
    public function isOpenAt(DateTimeInterface $at): bool
    {
        if (! $this->is_open) {
            return false;
        }

        $hours = $this->workHours();

        // Jadval bo'sh bo'lsa faqat is_open bayrog'iga tayanamiz.
        return $hours->isEmpty()
            || $hours->isOpenAt(CarbonImmutable::instance($at)->setTimezone(config('app.display_timezone')));
    }

    /** Reyting ommaviy ko'rsatiladigan minimal shart (Claude.md). */
    public const MIN_PUBLIC_RATING = 4.0;

    public const MIN_PUBLIC_RATINGS_COUNT = 5;

    /**
     * Reyting mijozga ko'rsatiladimi. Yo'q bo'lsa "Yangi" deb ko'rsatiladi —
     * past ball hech qachon ochiq chiqmaydi.
     */
    public function hasPublicRating(): bool
    {
        return $this->cached_average_rating !== null
            && $this->cached_average_rating >= self::MIN_PUBLIC_RATING
            && $this->cached_ratings_count >= self::MIN_PUBLIC_RATINGS_COUNT;
    }
}
