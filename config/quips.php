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
        'Punggung {name} butuh tukang urut, bukan defeat screen.',
        '{name} udah gendong sejauh ini, nexusnya aja yang nggak mau diajak kerja sama.',
        'Skor {name} bagus, hasilnya enggak. Hidup memang nggak adil.',
        'Kalah terhormat ala {name}: statistik cakep, hati hancur.',
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
        'Minion lawan kirim ucapan terima kasih buat {name}.',
        '{cs} CS/min. {name} lagi diet gold, ya?',
        'Cannon minion lewat gitu aja. {name}, itu uang jajanmu.',
        '{name} farming-nya santai, kayak lagi mancing.',
        'Gold gratis berbaris rapi, {name} memilih jalan-jalan.',
    ],

    'pentakill' => [
        'PENTAKILL! {name} baru saja menghapus satu tim penuh pakai {champ}.',
        'Lima nyawa, satu {name}. Klip ini wajib diupload.',
        'Tim lawan baru sadar kalau {name} itu bos terakhirnya.',
        'PENTA! Screenshot, bingkai, gantung di ruang tamu, {name}.',
        'Lima berturut-turut. {name} nggak kenal kata sisakan buat teman.',
        '{champ} milik {name} lagi buka layanan antar ke fountain, lima sekaligus.',
        'Announcer sampai capek teriak. Selamat atas pentakill-nya, {name}!',
        'PENTAKILL. Mulai sekarang {name} boleh sombong sampai minggu depan.',
        'Satu tim lawan jadi cuplikan highlight {name}.',
        '{name} borong semuanya. Teman satu tim cuma bisa jadi saksi.',
    ],

    // ---------- Durasi game ----------

    'short_game' => [ // < 20 menit
        '{minutes} menit doang. Lebih cepat dari masak mi instan.',
        'Surrender di menit {minutes}. Seseorang di game ini nyerah duluan.',
        'Game kilat, {name}. Mie belum mateng, game udah selesai.',
        'Speedrun ranked berhasil dalam {minutes} menit.',
        '{minutes} menit, belum sempat full item udah bubar.',
        'Cepat amat, {name}. Kopinya masih panas.',
        'Game {minutes} menit. Loading screen-nya hampir lebih berkesan.',
        'Selesai dalam {minutes} menit. {name} ada janji lain, ya?',
        'Tamat sebelum seru-serunya. Next game, {name}.',
        '{minutes} menit aja, hemat kuota hemat emosi.',
    ],

    'long_game' => [ // > 40 menit
        '{minutes} menit. {name} main ranked atau ikut maraton?',
        'Game sepanjang film bioskop. Popcornnya habis, {name}?',
        'Setelah {minutes} menit penuh drama, akhirnya selesai juga.',
        'Elder dragon pun bosan nungguin game ini selesai.',
        '{minutes} menit. {name} sudah makan belum?',
        'Inventory penuh, mental kosong. Game {minutes} menit yang melelahkan.',
        'Game selama ini harusnya dapat uang lembur, {name}.',
        '{minutes} menit kemudian, akhirnya ada yang ingat buat dorong nexus.',
        'Waktu {name} masuk game, matahari masih di posisi berbeda.',
        'Maraton {minutes} menit selesai. Peregangan dulu, {name}.',
    ],

    // ---------- Streak ----------

    'win_streak' => [ // streak menang >= 3
        '{streak} menang beruntun! {name} lagi panas, jangan diganggu.',
        '{name} on fire. Ada yang punya alat pemadam?',
        'Win streak {streak}. Riot mulai curiga sama {name}.',
        'Jangan berhenti sekarang {name}, Hokimu belum habis.',
        '{streak} kali menang terus. Bagi resepnya dong, {name}.',
        'Antre di belakang {name}, tiket gendongan masih tersedia.',
        '{name} lagi nggak bisa direm. Win ke-{streak}!',
        'Streak {streak}. Teman-teman mendadak rajin ngajak {name} duo.',
        '{name} menang lagi, menang terus. Lawan mulai trauma.',
        'Sudah {streak} kemenangan. Tolong {name} disisakan buat yang lain.',
    ],

    'loss_streak' => [ // streak kalah >= 3
        '{streak} kalah beruntun. {name}, mungkin ini tanda buat tidur.',
        'Loss streak {streak}. Saatnya {name} pindah ke ARAM dulu.',
        'Matchmaking lagi nguji kesabaran {name}. Jangan pukul monitor ya.',
        '{name}, uninstall itu bukan solusi. Tidur itu solusi.',
        'Kalah ke-{streak}. {name}, coba mandi dulu, siapa tahu hokinya balik.',
        '{streak} defeat berjejer rapi. Koleksi yang tidak diinginkan, {name}.',
        'Pelan-pelan {name}, tangga rank turunnya jangan sambil lari.',
        'Loss streak {streak}. Grup doa bersama buat {name} sudah dibuka.',
        '{name}, tombol "Find Match" bisa istirahat dulu.',
        'Badai pasti berlalu, {name}. Cuma ini badainya sudah {streak} game.',
    ],

    // ---------- Perubahan rank ----------

    'promoted' => [
        'Naik ke {tier}! Selamat {name}, akhirnya kerja keras terbayar.',
        '{name} resmi {tier}. Udara di atas lebih segar ya?',
        'Promosi ke {tier}. Traktirannya kapan, {name}?',
        'Welcome to {tier}, {name}. Jangan buru-buru turun lagi.',
        'Level up! {name} sekarang warga {tier}.',
        '{tier} unlocked. {name} resmi boleh sok ngajarin.',
        'Border baru, gaya baru. Congrats {name}, {tier} cocok buatmu.',
        '{name} tembus {tier}. Grup chat harap siap dipamerin.',
        'Akhirnya {tier} juga! {name} bisa tidur nyenyak malam ini.',
        'Tangga rank nambah satu: {name} mendarat di {tier}.',
    ],

    'demoted' => [
        '{name} turun ke {tier}. Anggap aja mundur selangkah buat lompat lebih jauh.',
        'Demote ke {tier}. Sementara aja kok, {name}. Semoga.',
        '{tier} kangen sama {name}, jadi dia mampir lagi.',
        'Turun ke {tier}. Nggak apa-apa {name}, yang penting masih main.',
        '{name} balik ke {tier}, katanya mau reuni sama teman lama.',
        'Pendaratan darurat di {tier}. {name} selamat, LP tidak.',
        '{tier} lagi butuh bantuan, jadi {name} turun tangan langsung.',
        'Jangan sedih {name}, dari {tier} pemandangan ke atas lebih jelas.',
        '{name} mampir dulu di {tier}. Besok naik lagi, kan?',
        'Demote itu cuma plot twist. Comeback arc {name} dimulai dari {tier}.',
    ],
];
