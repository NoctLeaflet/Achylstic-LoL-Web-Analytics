<?php

/*
|--------------------------------------------------------------------------
| Celetukan bot Discord (Achylstic)
|--------------------------------------------------------------------------
| Dipakai oleh App\Services\Discord\QuipPicker. Silakan tambah atau ubah kalimat.
| Kalimat hanya dipakai kalau semua placeholder-nya punya nilai. {lp} belum
| diketahui per game, jadi kalimat yang memakai {lp} saat ini dilewati.
|
| Placeholder yang tersedia:
|   {name}      nama pemain (display name di grup)
|   {champ}     champion yang dipakai pemain
|   {enemy}     champion lawan di lane yang sama
|   {kills} {deaths} {assists}  angka K/D/A
|   {kda}       nilai KDA (mis. 2.30)
|   {cs}        CS per menit
|   {lp}        perubahan LP (angka, tanpa tanda)
|   {tier}      rank baru (mis. PLATINUM IV)
|   {streak}    jumlah streak menang/kalah
|   {minutes}   durasi game dalam menit
|
| Urutan prioritas yang disarankan saat memilih kategori:
|   pentakill > promoted/demoted > feeding > carry_loss > stomp_win
|   > win_streak/loss_streak > long_game/short_game > low_cs > win/loss
*/

return [

    // ---------- Hasil dasar ----------

    'win' => [
        'GG {name}! {champ} hari ini lagi on, jangan lupa bersyukur.',
        '{name} menang. Tim lawan lagi ngecek replay sambil bengong.',
        '+{lp} LP masuk kantong. Kerja bagus, {name}.',
        '{enemy} pulang dengan kepala tertunduk. Nice one, {name}.',
        'Menang ya menang. Nggak usah dibahas siapa yang carry, {name}.',
        '{name} lagi rajin naik tangga. Pelan-pelan asal sampai.',
        'Satu langkah lebih dekat ke rank impian. Lanjut, {name}!',
        'Nexus lawan meledak, mood {name} naik.',
        '{champ} di tangan {name} tuh beda rasanya.',
        'Win is a win. Screenshot dulu sebelum lupa, {name}.',
    ],

    'loss' => [
        'Tough loss buat {name}. Tenang, {enemy} memang lagi OP Parah.',
        '{name} kalah, tapi yang penting mental tetap aman. Kan?',
        '-{lp} LP. Anggap aja sedekah ke Riot, {name}.',
        'Nggak apa-apa {name}, matchmaking memang suka bercanda.',
        '{enemy} perlu di-nerf. Bukan salah {name}, tentu saja.',
        'Kalah bukan akhir dunia. Akhir dunia itu kalau lanjut tilt, {name}.',
        '{name} kasih kesempatan tim lawan buat bahagia hari ini. Sangat Mulia.',
        'Istirahat sebentar, minum air, terus coba lagi, {name}.',
        'Tim lawan pasti smurf. Pasti. Ya kan, {name}?',
        'Game ini nggak usah diceritain ke siapa-siapa ya, {name}.',
        'Pura-pura gak lihat hasil matchnya {name}.',
    ],

    // ---------- Performa individu ----------

    'stomp_win' => [ // menang dengan KDA tinggi (mis. KDA >= 5)
        '{kills}/{deaths}/{assists}?? {name}, ini ranked atau latihan lawan bot?',
        '{name} main {champ} kayak pemegang patent-nya.',
        'KDA {kda}. Laporan resmi: {enemy} sudah pensiun dini.',
        'Tim lawan minta {name} di-ban, bukan {champ}-nya.',
        '{name} hari ini bukan pemain, tapi bencana alam.',
        'Ada yang lihat {enemy}? Terakhir kelihatan di fountain, nangis.',
        '{name} carry sampai punggungnya pegal. Kasih koyo.',
        'Pantas MMR lawan turun. {name} lagi kerasukan.',
    ],

    'carry_loss' => [ // kalah tapi KDA/damage paling bagus di tim
        '{kills}/{deaths}/{assists} tapi tetap kalah. {name}, kamu perlu tim yang lebih baik.',
        '{name} udah berusaha maksimal. Sisanya biar Tuhan dan matchmaking yang jawab.',
        'Carry 1v9 itu berat, {name}. Kadang 9-nya termasuk tim sendiri.',
        'Statistik bilang menang, nexus bilang kalah. Sabar ya {name}.',
        'KDA {kda} tapi defeat. {name} korban sistem.',
        '{name} jadi satu-satunya alasan game ini nggak kalah di menit 15.',
    ],

    'feeding' => [ // deaths tinggi (mis. deaths >= 10 dan KDA < 1)
        '{deaths} kali mati. {name} lagi ngumpulin sedekah poin buat {enemy}?',
        '{name} hari ini jadi ATM berjalan buat tim lawan.',
        'Shutdown gold {enemy} disponsori oleh {name}.',
        '{name}, fountain itu tempat belanja, bukan tempat nongkrong.',
        'Respawn timer {name} hari ini lebih lama dari durasi gamenya.',
        'Tenang {name}, kematianmu nggak sia-sia. {enemy} jadi kaya.',
        '{name} main {champ} kayak baru unlock tadi pagi.',
        'Grey screen simulator, starring {name}.',
    ],

    'low_cs' => [ // CS/menit rendah untuk role non-support (mis. < 4.5)
        '{cs} CS/min. Minion-minion itu juga butuh perhatian, {name}.',
        '{name} vegetarian, nggak makan minion.',
        'Wave-nya lewat, {name} cuma ngelihatin. Damai banget.',
        'Last hit itu fitur, bukan saran, {name}.',
        '{name} membiarkan minion hidup. Pasifis sejati.',
    ],

    'pentakill' => [
        'PENTAKILL! {name} baru saja menghapus satu tim penuh pakai {champ}.',
        'Lima nyawa, satu {name}. Klip ini wajib diupload.',
        'Tim lawan baru sadar kalau {name} itu bos terakhirnya.',
        'PENTA! Screenshot, bingkai, gantung di ruang tamu, {name}.',
    ],

    // ---------- Durasi game ----------

    'short_game' => [ // < 20 menit
        '{minutes} menit doang. Lebih cepat dari masak mi instan.',
        'Surrender di menit {minutes}. Seseorang di game ini nyerah duluan.',
        'Game kilat, {name}. Mie belum mateng, game udah selesai.',
        'Speedrun ranked berhasil dalam {minutes} menit.',
    ],

    'long_game' => [ // > 40 menit
        '{minutes} menit. {name} main ranked atau ikut maraton?',
        'Game sepanjang film bioskop. Popcornnya habis, {name}?',
        'Setelah {minutes} menit penuh drama, akhirnya selesai juga.',
        'Elder dragon pun bosan nungguin game ini selesai.',
    ],

    // ---------- Streak ----------

    'win_streak' => [ // streak menang >= 3
        '{streak} menang beruntun! {name} lagi panas, jangan diganggu.',
        '{name} on fire. Ada yang punya alat pemadam?',
        'Win streak {streak}. Riot mulai curiga sama {name}.',
        'Jangan berhenti sekarang {name}, Hokimu belum habis.',
    ],

    'loss_streak' => [ // streak kalah >= 3
        '{streak} kalah beruntun. {name}, mungkin ini tanda buat tidur.',
        'Loss streak {streak}. Saatnya {name} pindah ke ARAM dulu.',
        'Matchmaking lagi nguji kesabaran {name}. Jangan pukul monitor ya.',
        '{name}, uninstall itu bukan solusi. Tidur itu solusi.',
    ],

    // ---------- Perubahan rank ----------

    'promoted' => [
        'Naik ke {tier}! Selamat {name}, akhirnya kerja keras terbayar.',
        '{name} resmi {tier}. Udara di atas lebih segar ya?',
        'Promosi ke {tier}. Traktirannya kapan, {name}?',
        'Welcome to {tier}, {name}. Jangan buru-buru turun lagi.',
    ],

    'demoted' => [
        '{name} turun ke {tier}. Anggap aja mundur selangkah buat lompat lebih jauh.',
        'Demote ke {tier}. Sementara aja kok, {name}. Semoga.',
        '{tier} kangen sama {name}, jadi dia mampir lagi.',
        'Turun ke {tier}. Nggak apa-apa {name}, yang penting masih main.',
    ],
];
