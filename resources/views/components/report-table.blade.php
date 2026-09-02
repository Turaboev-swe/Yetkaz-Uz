@props(['head' => [], 'rows' => [], 'empty' => 'Ma\'lumot yo\'q'])

<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead>
            <tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10 dark:text-gray-400">
                @foreach ($head as $i => $col)
                    <th @class(['py-2 pr-4 font-medium', 'text-right' => $i > 0])>{{ $col }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr class="border-b border-gray-100 dark:border-white/5">
                    @foreach ($row as $i => $cell)
                        <td @class([
                            'py-2 pr-4 text-gray-950 dark:text-white',
                            'text-right tabular-nums' => $i > 0,
                            'font-medium' => $i === 0,
                        ])>{{ $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ max(count($head), 1) }}" class="py-6 text-center text-gray-500 dark:text-gray-400">
                        {{ $empty }}
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
