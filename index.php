<?php
// 0. PHP HÁTTÉRKÓD: Dinamikus Albumborító Kiszolgáló
if (isset($_GET['cover'])) {
    $file = $_GET['cover'];
    if (file_exists($file)) {
        $f = fopen($file, 'rb');
        if ($f) {
            $header = fread($f, 10);
            if (substr($header, 0, 3) === 'ID3') {
                $sizeBytes = substr($header, 6, 4);
                $tagSize = (ord($sizeBytes[0]) << 21) | (ord($sizeBytes[1]) << 14) | (ord($sizeBytes[2]) << 7) | ord($sizeBytes[3]);
                $tagData = fread($f, $tagSize);
                $pos = strpos($tagData, 'APIC');
                if ($pos !== false) {
                    $sizeData = substr($tagData, $pos + 4, 4);
                    $size = unpack('N', $sizeData)[1];
                    if ($size > 0 && $size < 5000000) { 
                        $frameData = substr($tagData, $pos + 10, $size);
                        $jpegPos = strpos($frameData, "\xFF\xD8\xFF");
                        $pngPos = strpos($frameData, "\x89\x50\x4E\x47");
                        if ($jpegPos !== false && ($pngPos === false || $jpegPos < $pngPos)) {
                            header('Content-Type: image/jpeg');
                            echo substr($frameData, $jpegPos);
                            exit;
                        } elseif ($pngPos !== false) {
                            header('Content-Type: image/png');
                            echo substr($frameData, $pngPos);
                            exit;
                        }
                    }
                }
            }
            fclose($f);
        }
    }
    header('Content-Type: image/svg+xml');
    echo '<svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><rect width="24" height="24" fill="#282828"/><path d="M12 3v10.55c-.59-.34-1.27-.55-2-.55-2.21 0-4 1.79-4 4s1.79 4 4 4 4-1.79 4-4V7h4V3h-6z" fill="#b3b3b3"/></svg>';
    exit;
}

// 1. PHP HÁTTÉRKÓD: Natív MP3 Metaadat Olvasó
function getMp3Info($filePath) {
    $info = ['title' => '', 'artist' => '', 'album' => ''];
    if (!file_exists($filePath)) return $info;

    $file = fopen($filePath, 'rb');
    if (!$file) return $info;

    $header = fread($file, 10);
    if (substr($header, 0, 3) === 'ID3') {
        $sizeBytes = substr($header, 6, 4);
        $tagSize = (ord($sizeBytes[0]) << 21) | (ord($sizeBytes[1]) << 14) | (ord($sizeBytes[2]) << 7) | ord($sizeBytes[3]);
        $tagData = fread($file, $tagSize);
        
        $info['title'] = extractId3v2Frame('TIT2', $tagData) ?: $info['title'];
        $info['artist'] = extractId3v2Frame('TPE1', $tagData) ?: $info['artist'];
        $info['album'] = extractId3v2Frame('TALB', $tagData) ?: $info['album'];
    }

    if (empty($info['title']) || empty($info['artist'])) {
        fseek($file, -128, SEEK_END);
        $id3v1 = fread($file, 128);
        if (substr($id3v1, 0, 3) === 'TAG') {
            $info['title'] = mb_convert_encoding(trim(substr($id3v1, 3, 30)), 'UTF-8', 'ISO-8859-1') ?: $info['title'];
            $info['artist'] = mb_convert_encoding(trim(substr($id3v1, 33, 30)), 'UTF-8', 'ISO-8859-1') ?: $info['artist'];
            $info['album'] = mb_convert_encoding(trim(substr($id3v1, 63, 30)), 'UTF-8', 'ISO-8859-1') ?: $info['album'];
        }
    }
    fclose($file);
    
    return array_map(function($val) {
        $val = (string)$val;
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/', '', $val);
        return trim(str_replace("\x00", "", (string)$cleaned));
    }, $info);
}

function extractId3v2Frame($frameId, $tagData) {
    $pos = strpos($tagData, $frameId);
    if ($pos !== false) {
        $sizeData = substr($tagData, $pos + 4, 4);
        $size = unpack('N', $sizeData)[1];
        if ($size > 0 && $size < 2000) { 
            $textBlock = substr($tagData, $pos + 10, $size);
            if (strlen($textBlock) > 0) {
                $encodingByte = ord($textBlock[0]);
                $text = substr($textBlock, 1);
                
                if ($encodingByte == 0) {
                    return mb_convert_encoding($text, 'UTF-8', 'ISO-8859-1'); 
                } elseif ($encodingByte == 1 || $encodingByte == 2) {
                    return mb_convert_encoding($text, 'UTF-8', 'UTF-16');
                }
                return $text; 
            }
        }
    }
    return '';
}

// 2. Zenék beolvasása és rendezése
$musicDir = '.'; 
$albums = [];
$allSongs = []; 

if (is_dir($musicDir)) {
    $files = glob($musicDir . '/*.mp3');
    foreach ($files as $file) {
        $filename = basename($file, '.mp3');
        $meta = getMp3Info($file);
        
        $title = !empty($meta['title']) ? $meta['title'] : $filename;
        $artist = !empty($meta['artist']) ? $meta['artist'] : 'Ismeretlen Előadó';
        $albumName = !empty($meta['album']) ? $meta['album'] : 'Ismeretlen Album';

        $artist = str_replace('/', ' & ', $artist);

        $songData = [
            'file' => $file,
            'title' => $title,
            'artist' => $artist,
            'album' => $albumName
        ];

        if (!isset($albums[$albumName])) {
            $albums[$albumName] = ['artists' => [], 'songs' => []];
        }

        $individualArtists = array_map('trim', explode('&', $artist));
        foreach ($individualArtists as $indArtist) {
            if ($indArtist !== '' && !in_array($indArtist, $albums[$albumName]['artists'])) {
                $albums[$albumName]['artists'][] = $indArtist;
            }
        }

        $albums[$albumName]['songs'][] = $songData;
        $allSongs[] = $songData;
    }
    
    foreach ($albums as $name => &$data) {
        $data['artist'] = implode(', ', $data['artists']);
    }
    unset($data);

    usort($allSongs, function($a, $b) {
        return strcoll(strtolower($a['title']), strtolower($b['title']));
    });

    ksort($albums);
    
    if (isset($albums['Ismeretlen Album'])) {
        $unknownData = $albums['Ismeretlen Album'];
        unset($albums['Ismeretlen Album']);
        $albums['Ismeretlen Album'] = $unknownData;
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Music Player</title>
    <style>
        /* CSS: Minimalista, reszponzív dizájn Egyedi Vezérlőkkel */
        :root {
            --bg-color: #121212;
            --surface-color: #181818;
            --text-primary: #ffffff;
            --text-secondary: #a7a7a7;
            --accent-color: #1db954; 
            --slider-bg: #4d4d4d;
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        body { background-color: var(--bg-color); color: var(--text-primary); padding-bottom: 120px; user-select: none; overflow-x: hidden; }
        
        :focus { outline: none; } 
        :focus-visible { outline: 2px solid var(--accent-color); outline-offset: 2px; border-radius: 4px; }
        input:focus { outline: none; } 
        .song-item:focus-visible { background-color: #333; outline: 2px solid var(--accent-color); outline-offset: -2px; }
        
        header { padding: 15px 20px; background-color: rgba(18, 18, 18, 0.95); position: sticky; top: 0; z-index: 100; border-bottom: 1px solid #282828; display: flex; flex-direction: column; gap: 15px; }
        .header-controls { display: flex; justify-content: space-between; align-items: center; max-width: 1200px; margin: 0 auto; width: 100%; gap: 20px; }
        .search-container { flex-grow: 1; max-width: 400px; }
        .search-container input { width: 100%; padding: 10px 15px; border-radius: 20px; border: none; background-color: #2a2a2a; color: white; font-size: 14px; outline: none; transition: 0.3s; }
        .search-container input:focus { background-color: #333; box-shadow: 0 0 0 2px var(--accent-color); }
        .view-toggle { display: flex; background-color: #2a2a2a; border-radius: 20px; overflow: hidden; }
        .view-btn { background: none; border: none; color: var(--text-secondary); padding: 8px 15px; font-size: 13px; cursor: pointer; transition: 0.2s; font-weight: bold; }
        .view-btn.active { background-color: #3e3e3e; color: var(--text-primary); }
        
        .container { padding: 20px; max-width: 1200px; margin: 0 auto; transition: opacity 0.3s; }
        .album-section { margin-bottom: 40px; }
        .album-header { margin-bottom: 15px; border-bottom: 1px solid #282828; padding-bottom: 10px; }
        .album-title { font-size: 24px; font-weight: bold; }
        .album-artist { font-size: 14px; color: var(--text-secondary); margin-top: 5px; line-height: 1.4; }
        
        .song-list { list-style: none; }
        .song-item { display: flex; align-items: center; padding: 10px; border-radius: 5px; cursor: pointer; transition: background 0.2s; }
        .song-item:hover { background-color: #2a2a2a; }
        .song-item:hover .song-icon svg { fill: var(--text-primary); }
        .song-item.active { background-color: #2a2a2a; }
        .song-item.active .song-title { color: var(--accent-color); }
        .song-item.active .song-icon svg { fill: var(--accent-color); }
        
        .song-icon { margin-right: 15px; color: var(--text-secondary); width: 24px; height: 24px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .song-icon svg { width: 16px; height: 16px; fill: var(--text-secondary); transition: fill 0.2s; }
        
        .song-details { display: flex; flex-direction: column; flex-grow: 1; overflow: hidden; }
        .song-title { font-size: 16px; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .song-artist-inline { font-size: 13px; color: var(--text-secondary); margin-top: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        .btn-add-queue { background: none; border: none; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 8px; margin-left: 10px; opacity: 0.5; transition: 0.2s; border-radius: 50%; }
        .btn-add-queue svg { width: 20px; height: 20px; fill: currentColor; }
        .btn-add-queue:hover, .btn-add-queue:focus-visible { opacity: 1; color: var(--accent-color); background-color: rgba(255,255,255,0.05); }
        
        /* VÁRÓLISTA PANEL */
        .queue-panel { position: fixed; right: -350px; top: 0; bottom: 90px; width: 350px; background-color: rgba(24,24,24,0.98); border-left: 1px solid #282828; z-index: 900; transition: right 0.3s ease; display: flex; flex-direction: column; box-shadow: -5px 0 15px rgba(0,0,0,0.5); }
        .queue-panel.open { right: 0; }
        .queue-header { padding: 20px; border-bottom: 1px solid #282828; display: flex; justify-content: space-between; align-items: center; }
        .queue-header h2 { font-size: 18px; font-weight: bold; }
        .queue-list { flex-grow: 1; overflow-y: auto; padding: 10px; list-style: none; }
        
        .q-item { display: flex; align-items: center; background-color: #2a2a2a; margin-bottom: 8px; padding: 10px; border-radius: 5px; cursor: grab; }
        .q-item:active { cursor: grabbing; }
        .q-item.dragging { opacity: 0.4; border: 1px dashed var(--accent-color); }
        .q-drag-handle { color: var(--text-secondary); margin-right: 15px; cursor: inherit; display: flex; align-items: center; justify-content: center; }
        .q-drag-handle svg { width: 16px; height: 16px; fill: currentColor; }
        .q-details { flex-grow: 1; overflow: hidden; display: flex; flex-direction: column; }
        .q-title { font-size: 14px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .q-artist { font-size: 12px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: margin-top: 2px; }
        .btn-q-remove { background: none; border: none; color: var(--text-secondary); padding: 5px; cursor: pointer; transition: color 0.2s; margin-left: 10px; }
        .btn-q-remove:hover, .btn-q-remove:focus-visible { color: #ff4d4d; }
        .btn-q-remove svg { width: 16px; height: 16px; fill: currentColor; }
        .queue-empty-msg { text-align: center; color: var(--text-secondary); font-size: 14px; margin-top: 30px; }

        /* LEJÁTSZÓ SÁV */
        .player-bar { position: fixed; bottom: 0; left: 0; right: 0; height: 90px; background-color: var(--surface-color); border-top: 1px solid #282828; display: flex; align-items: center; padding: 0 20px; justify-content: space-between; z-index: 1000; }
        
        .now-playing { display: flex; flex-direction: row; align-items: center; gap: 15px; width: 30%; min-width: 220px; overflow: hidden; }
        .np-cover-img { width: 56px; height: 56px; border-radius: 4px; object-fit: cover; background-color: #282828; flex-shrink: 0; box-shadow: 0 2px 10px rgba(0,0,0,0.3); }
        
        .np-info-wrapper { flex-grow: 1; overflow: hidden; white-space: nowrap; }
        .np-info { display: flex; flex-direction: column; }
        .np-title { font-size: 14px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-primary); }
        .np-artist { font-size: 12px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px;}
        
        .player-controls { display: flex; flex-direction: column; align-items: center; justify-content: center; width: 40%; max-width: 600px; }
        .control-buttons { display: flex; align-items: center; gap: 24px; margin-bottom: 8px; }
        
        .btn-icon { background: none; border: none; color: var(--text-secondary); cursor: pointer; display: flex; align-items: center; justify-content: center; transition: color 0.2s; position: relative; }
        .btn-icon svg { width: 18px; height: 18px; fill: currentColor; }
        .btn-icon:hover, .btn-icon:focus-visible { color: var(--text-primary); }
        .btn-icon.active-mode { color: var(--accent-color); }
        
        .btn-queue-toggle.has-items::after { content: ''; position: absolute; top: -4px; right: -4px; width: 8px; height: 8px; background-color: var(--accent-color); border-radius: 50%; }
        
        .btn-play-pause { width: 36px; height: 36px; border-radius: 50%; background-color: var(--text-primary); color: var(--bg-color); transition: transform 0.1s, background-color 0.2s; }
        .btn-play-pause svg { width: 20px; height: 20px; fill: currentColor; }
        .btn-play-pause:hover, .btn-play-pause:focus-visible { transform: scale(1.05); color: var(--bg-color); background-color: white; }
        
        .progress-container { display: flex; align-items: center; gap: 10px; width: 100%; font-size: 12px; color: var(--text-secondary); font-variant-numeric: tabular-nums; }
        input[type="range"] { -webkit-appearance: none; width: 100%; height: 4px; border-radius: 2px; outline: none; cursor: pointer; --val: 0%; --fill-color: var(--text-primary); background: linear-gradient(to right, var(--fill-color) var(--val), var(--slider-bg) var(--val)); }
        input[type="range"]::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 12px; height: 12px; border-radius: 50%; background: var(--text-primary); cursor: pointer; opacity: 0; transition: opacity 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.5); }
        .progress-container:hover input[type="range"]::-webkit-slider-thumb, .volume-container:hover input[type="range"]::-webkit-slider-thumb { opacity: 1; }
        input[type="range"]:focus-visible::-webkit-slider-thumb { opacity: 1; background: var(--accent-color); }
        input[type="range"]:hover { --fill-color: var(--accent-color); }
        
        .right-controls { display: flex; align-items: center; justify-content: flex-end; width: 30%; gap: 15px; }
        
        .volume-container { display: flex; align-items: center; gap: 10px; color: var(--text-secondary); }
        .volume-container input[type="range"] { max-width: 100px; --val: 100%; }
        .volume-icon-wrapper { display: flex; align-items: center; justify-content: center; width: 20px; height: 20px; }
        .volume-icon-wrapper svg { width: 16px; height: 16px; fill: currentColor; }
        
        /* MOBILOS NÉZET OPTIMALIZÁLÁSA & MARQUEE ANIMÁCIÓ */
        @keyframes pingpong {
            0%, 15% { transform: translateX(0); }
            85%, 100% { transform: translateX(var(--scroll-dist)); }
        }

        .needs-marquee { animation: pingpong 6s ease-in-out infinite alternate; }

        @media (max-width: 768px) {
            body { padding-bottom: 160px; } 
            .header-controls { flex-direction: column; align-items: stretch; }
            .view-toggle { justify-content: center; }
            
            .player-bar { 
                flex-direction: column; 
                height: auto; 
                padding: 15px 15px 25px 15px; 
                padding-bottom: calc(25px + env(safe-area-inset-bottom)); 
                gap: 15px; 
            }
            .np-cover-img { display: none; } 
            
            .now-playing { width: 100%; justify-content: center; min-width: auto; margin-bottom: 5px; overflow: hidden; }
            .np-info-wrapper { text-align: center; width: 100%; overflow: hidden; }
            
            .np-info { display: inline-flex; flex-direction: row; justify-content: center; align-items: baseline; gap: 6px; width: max-content; }
            
            .np-title { font-size: 15px; text-overflow: clip; }
            .np-artist { font-size: 13px; margin-top: 0; text-overflow: clip; }
            .np-artist:not(:empty)::before { content: '• '; margin-right: 6px; } 
            
            .player-controls { width: 100%; }
            .control-buttons { width: 100%; justify-content: space-between; max-width: 340px; margin: 0 auto 10px auto; padding: 0 10px; }
            .btn-icon svg { width: 22px; height: 22px; } 
            .btn-play-pause { width: 44px; height: 44px; }
            .btn-play-pause svg { width: 24px; height: 24px; }
            
            .right-controls { display: none; } 
            .queue-panel { width: 100%; right: -100%; bottom: 135px; } 
        }
    </style>
</head>
<body>

<header>
    <div class="header-controls">
        <div class="search-container">
            <input type="text" id="searchInput" placeholder="Keresés..." tabindex="0">
        </div>
        <div class="view-toggle">
            <button class="view-btn active" id="btnViewList" tabindex="-1">Zeneszámok</button>
            <button class="view-btn" id="btnViewAlbum" tabindex="-1">Albumok</button>
        </div>
    </div>
</header>

<?php 
$listPlaySvg = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>'; 
$queueAddSvg = '<svg viewBox="0 0 24 24"><path d="M14 10H2v2h12v-2zm0-4H2v2h12V6zm4 8v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM2 16h8v-2H2v2z"/></svg>';
?>

<div id="queuePanel" class="queue-panel">
    <div class="queue-header">
        <h2>Saját Műsor</h2>
        <button class="btn-icon" id="btnCloseQueue" title="Bezárás" tabindex="-1"><svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg></button>
    </div>
    <ul id="queueList" class="queue-list">
        <div class="queue-empty-msg">Nincsenek számok a műsorban.</div>
    </ul>
</div>

<div class="container" id="mainContainer">
    <?php if (empty($albums)): ?>
        <p style="color: var(--text-secondary); text-align: center; margin-top: 50px;">
            Nincsenek .mp3 fájlok a mappában.
        </p>
    <?php else: ?>

        <div id="viewListContainer" style="display: block;">
            <ul class="song-list">
                <?php foreach ($allSongs as $song): ?>
                    <li class="song-item searchable-item" tabindex="0"
                        data-file="<?php echo htmlspecialchars($song['file']); ?>" 
                        data-title="<?php echo htmlspecialchars($song['title']); ?>" 
                        data-artist="<?php echo htmlspecialchars($song['artist']); ?>"
                        data-search-text="<?php echo htmlspecialchars(strtolower($song['title'] . ' ' . $song['artist'])); ?>">
                        <div class="song-icon"><?php echo $listPlaySvg; ?></div>
                        <div class="song-details">
                            <span class="song-title"><?php echo htmlspecialchars($song['title']); ?></span>
                            <span class="song-artist-inline"><?php echo htmlspecialchars($song['artist']); ?> • <?php echo htmlspecialchars($song['album']); ?></span>
                        </div>
                        <button class="btn-add-queue" tabindex="-1" title="Hozzáadás a saját műsorhoz" data-file="<?php echo htmlspecialchars($song['file']); ?>">
                            <?php echo $queueAddSvg; ?>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>

        <div id="viewAlbumContainer" style="display: none;">
            <?php foreach ($albums as $albumName => $albumData): ?>
                <div class="album-section searchable-item" data-search-text="<?php echo htmlspecialchars(strtolower($albumName . ' ' . $albumData['artist'])); ?>">
                    <div class="album-header">
                        <div class="album-title"><?php echo htmlspecialchars($albumName); ?></div>
                        <?php if ($albumName !== 'Ismeretlen Album'): ?>
                            <div class="album-artist"><?php echo htmlspecialchars($albumData['artist']); ?></div>
                        <?php endif; ?>
                    </div>
                    <ul class="song-list">
                        <?php foreach ($albumData['songs'] as $song): ?>
                            <li class="song-item searchable-item" tabindex="0"
                                data-file="<?php echo htmlspecialchars($song['file']); ?>" 
                                data-title="<?php echo htmlspecialchars($song['title']); ?>" 
                                data-artist="<?php echo htmlspecialchars($song['artist']); ?>"
                                data-search-text="<?php echo htmlspecialchars(strtolower($song['title'] . ' ' . $song['artist'])); ?>">
                                <div class="song-icon"><?php echo $listPlaySvg; ?></div>
                                <div class="song-details">
                                    <span class="song-title"><?php echo htmlspecialchars($song['title']); ?></span>
                                    <span class="song-artist-inline"><?php echo htmlspecialchars($song['artist']); ?></span>
                                </div>
                                <button class="btn-add-queue" tabindex="-1" title="Hozzáadás a saját műsorhoz" data-file="<?php echo htmlspecialchars($song['file']); ?>">
                                    <?php echo $queueAddSvg; ?>
                                </button>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>
</div>

<div class="player-bar">
    <div class="now-playing">
        <img id="npCover" class="np-cover-img" src="?cover=default" alt="">
        <div class="np-info-wrapper">
            <div class="np-info" id="npInfoScroll">
                <span class="np-title" id="npTitle">Nincs lejátszás</span>
                <span class="np-artist" id="npArtist"></span>
            </div>
        </div>
    </div>
    
    <div class="player-controls">
        <div class="control-buttons">
            <button class="btn-icon" id="btnShuffle" tabindex="-1" title="Véletlenszerű lejátszás (Ctrl+S)">
                <svg viewBox="0 0 24 24"><path d="M10.59 9.17L5.41 4 4 5.41l5.17 5.17 1.42-1.41zM14.5 4l2.04 2.04L4 18.59 5.41 20 17.96 7.46 20 9.5V4h-5.5zm.33 9.41l-1.41 1.41 3.13 3.13L14.5 20H20v-5.5l-2.04 2.04-3.13-3.13z"/></svg>
            </button>
            <button class="btn-icon" id="btnPrev" tabindex="-1" title="Előző (Ctrl+Balra)">
                <svg viewBox="0 0 24 24"><path d="M6 6h2v12H6zm3.5 6l8.5 6V6z"/></svg>
            </button>
            <button class="btn-icon btn-play-pause" tabindex="-1" id="btnPlayPause" title="Lejátszás/Szünet (Space)">
                <svg viewBox="0 0 24 24" id="iconPlay"><path d="M8 5v14l11-7z"/></svg>
            </button>
            <button class="btn-icon" id="btnNext" tabindex="-1" title="Következő (Ctrl+Jobbra)">
                <svg viewBox="0 0 24 24"><path d="M6 18l8.5-6L6 6v12zM16 6v12h2V6h-2z"/></svg>
            </button>
            <button class="btn-icon btn-queue-toggle" tabindex="-1" id="btnToggleQueue" title="Műsor">
                <svg viewBox="0 0 24 24"><path d="M15 6H3v2h12V6zm0 4H3v2h12v-2zM3 16h8v-2H3v2zM17 6v8.18c-.31-.11-.65-.18-1-.18-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3V8h3V6h-5z"/></svg>
            </button>
        </div>
        <div class="progress-container">
            <span id="currentTime">0:00</span>
            <input type="range" id="progressBar" tabindex="-1" value="0" min="0" max="100" step="0.1">
            <span id="totalTime">0:00</span>
        </div>
    </div>
    
    <div class="right-controls">
        <div class="volume-container">
            <div class="volume-icon-wrapper" id="volumeIcon" title="Némítás (Ctrl+M)">
                <svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
            </div>
            <input type="range" id="volumeBar" tabindex="-1" value="100" min="0" max="100" step="1">
        </div>
    </div>
    
    <audio id="audioPlayer" style="display: none;">
        <source src="" type="audio/mpeg">
    </audio>
</div>

<script>
    const svgPlay = '<svg viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>';
    const svgPause = '<svg viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>';
    const svgCheck = '<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>';
    const svgDragHandle = '<svg viewBox="0 0 24 24"><path d="M3 15h18v-2H3v2zm0-4h18V9H3v2zm0-4h18V5H3v2z"/></svg>';
    const svgRemove = '<svg viewBox="0 0 24 24"><path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>';
    
    const svgVolHigh = '<svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>';
    const svgVolMid = '<svg viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L7 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02z"/></svg>';
    const svgVolMute = '<svg viewBox="0 0 24 24"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06c1.38-.31 2.63-.95 3.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>';

    const audioPlayer = document.getElementById('audioPlayer');
    const npTitle = document.getElementById('npTitle');
    const npArtist = document.getElementById('npArtist');
    const npCover = document.getElementById('npCover');
    const npInfoScroll = document.getElementById('npInfoScroll');
    const btnPlayPause = document.getElementById('btnPlayPause');
    const btnPrev = document.getElementById('btnPrev');
    const btnNext = document.getElementById('btnNext');
    const btnShuffle = document.getElementById('btnShuffle');
    const progressBar = document.getElementById('progressBar');
    const currentTimeEl = document.getElementById('currentTime');
    const totalTimeEl = document.getElementById('totalTime');
    const volumeBar = document.getElementById('volumeBar');
    const volumeIcon = document.getElementById('volumeIcon');
    
    const queuePanel = document.getElementById('queuePanel');
    const btnToggleQueue = document.getElementById('btnToggleQueue');
    const btnCloseQueue = document.getElementById('btnCloseQueue');
    const queueListEl = document.getElementById('queueList');
    const searchInput = document.getElementById('searchInput');
    
    let isPlaying = false;
    let isSeeking = false;
    let isShuffle = false;
    
    let playQueue = [];
    let playHistory = [];

    // Némítási változók
    let previousVolume = 100;
    let isMuted = false;

    // --- MARQUEE (PING-PONG) LOGIKA MOBILRA ---
    function checkMarquee() {
        if (window.innerWidth > 768) {
            npInfoScroll.classList.remove('needs-marquee');
            npInfoScroll.style.transform = 'translateX(0)';
            return;
        }
        
        npInfoScroll.classList.remove('needs-marquee');
        npInfoScroll.style.transform = 'translateX(0)';
        
        setTimeout(() => {
            const wrapperWidth = npInfoScroll.parentElement.clientWidth;
            const scrollWidth = npInfoScroll.scrollWidth;
            
            if (scrollWidth > wrapperWidth) {
                const dist = (scrollWidth - wrapperWidth) + 20; 
                npInfoScroll.style.setProperty('--scroll-dist', `-${dist}px`);
                npInfoScroll.classList.add('needs-marquee');
            }
        }, 100);
    }

    window.addEventListener('resize', checkMarquee);

    // --- NÉMÍTÁS LOGIKA ---
    function toggleMute() {
        if (isMuted) {
            volumeBar.value = previousVolume > 0 ? previousVolume : 100;
            isMuted = false;
        } else {
            previousVolume = volumeBar.value > 0 ? volumeBar.value : 100;
            volumeBar.value = 0;
            isMuted = true;
        }
        volumeBar.dispatchEvent(new Event('input')); 
    }

    // --- TAB TOGGLE & BILLENTYŰPARANCSOK ---
    document.addEventListener('keydown', function(e) {
        const isInputFocus = document.activeElement === searchInput;

        // TAB: Csak Kereső és Lista között vált (Toggle)
        if (e.key === 'Tab') {
            e.preventDefault();
            if (isInputFocus) {
                const activeContainer = document.getElementById('viewListContainer').style.display !== 'none' ? document.getElementById('viewListContainer') : document.getElementById('viewAlbumContainer');
                const firstVisible = Array.from(activeContainer.querySelectorAll('.song-item')).find(el => el.style.display !== 'none');
                if (firstVisible) firstVisible.focus();
            } else {
                searchInput.focus();
            }
            return;
        }

        // Space: Play/Pause
        if (e.code === 'Space' && !isInputFocus) {
            e.preventDefault(); 
            if (!audioPlayer.src || audioPlayer.src.endsWith('index.php')) return;
            if (isPlaying) pauseAudio(); else playAudio();
        }

        // Backspace: Előző oldal
        if (e.key === 'Backspace' && !isInputFocus) {
            e.preventDefault();
            window.history.back();
        }

        // Escape: Fókusz megszüntetése, vagy egy könyvtárral feljebb
        if (e.key === 'Escape') {
            e.preventDefault();
            if (document.activeElement && document.activeElement !== document.body) {
                document.activeElement.blur(); 
            } else {
                window.location.href = '../'; 
            }
        }
        
        // Enter a listában
        if (e.key === 'Enter' && document.activeElement.classList.contains('song-item')) {
            document.activeElement.click();
        }

        // Ctrl / Cmd kombók
        if (e.ctrlKey || e.metaKey) {
            if (e.key === 'ArrowRight') {
                e.preventDefault(); playNext();
            } else if (e.key === 'ArrowLeft') {
                e.preventDefault(); playPrev();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                volumeBar.value = Math.min(100, parseInt(volumeBar.value) + 10);
                volumeBar.dispatchEvent(new Event('input'));
            } else if (e.key === 'ArrowDown') {
                e.preventDefault();
                volumeBar.value = Math.max(0, parseInt(volumeBar.value) - 10);
                volumeBar.dispatchEvent(new Event('input'));
            } else if (e.key.toLowerCase() === 's') {
                e.preventDefault(); // Megakadályozza a mentés ablakot
                btnShuffle.click();
            } else if (e.key.toLowerCase() === 'm') {
                e.preventDefault();
                toggleMute();
            }
        } else {
            // Sima nyilak: Navigáció a listában
            if ((e.key === 'ArrowDown' || e.key === 'ArrowUp') && !isInputFocus) {
                e.preventDefault();
                const activeContainer = document.getElementById('viewListContainer').style.display !== 'none' ? document.getElementById('viewListContainer') : document.getElementById('viewAlbumContainer');
                const visibleSongs = Array.from(activeContainer.querySelectorAll('.song-item')).filter(el => el.style.display !== 'none');
                
                if (visibleSongs.length === 0) return;

                let currentIndex = visibleSongs.indexOf(document.activeElement);
                
                if (e.key === 'ArrowDown') {
                    currentIndex = currentIndex < visibleSongs.length - 1 ? currentIndex + 1 : 0;
                } else {
                    currentIndex = currentIndex > 0 ? currentIndex - 1 : visibleSongs.length - 1;
                }
                
                visibleSongs[currentIndex].focus();
            }
        }
    });

    // --- MŰSOR PANEL KEZELÉSE ---
    btnToggleQueue.addEventListener('click', () => queuePanel.classList.toggle('open'));
    btnCloseQueue.addEventListener('click', () => queuePanel.classList.remove('open'));

    function renderQueue() {
        queueListEl.innerHTML = '';
        if (playQueue.length === 0) {
            queueListEl.innerHTML = '<div class="queue-empty-msg">Nincsenek számok a műsorban.</div>';
            btnToggleQueue.classList.remove('has-items');
            return;
        }
        
        btnToggleQueue.classList.add('has-items');
        
        playQueue.forEach((song, index) => {
            const li = document.createElement('li');
            li.className = 'q-item';
            li.draggable = true;
            li.innerHTML = `
                <div class="q-drag-handle">${svgDragHandle}</div>
                <div class="q-details">
                    <span class="q-title">${song.title}</span>
                    <span class="q-artist">${song.artist}</span>
                </div>
                <button class="btn-q-remove" data-index="${index}" title="Törlés">${svgRemove}</button>
            `;
            
            li.querySelector('.btn-q-remove').addEventListener('click', function() {
                playQueue.splice(index, 1);
                renderQueue();
            });

            li.addEventListener('dragstart', () => { li.classList.add('dragging'); });
            li.addEventListener('dragend', () => {
                li.classList.remove('dragging');
                const newQueue = [];
                document.querySelectorAll('#queueList .q-item').forEach((el) => {
                    const title = el.querySelector('.q-title').textContent;
                    const artist = el.querySelector('.q-artist').textContent;
                    const origSong = playQueue.find(s => s.title === title && s.artist === artist);
                    if (origSong) newQueue.push(origSong);
                });
                playQueue = newQueue;
                renderQueue();
            });
            
            queueListEl.appendChild(li);
        });
    }

    queueListEl.addEventListener('dragover', e => {
        e.preventDefault();
        const afterElement = getDragAfterElement(queueListEl, e.clientY);
        const draggable = document.querySelector('.dragging');
        if (afterElement == null) {
            queueListEl.appendChild(draggable);
        } else {
            queueListEl.insertBefore(draggable, afterElement);
        }
    });

    function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll('.q-item:not(.dragging)')];
        return draggableElements.reduce((closest, child) => {
            const box = child.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset: offset, element: child };
            } else {
                return closest;
            }
        }, { offset: Number.NEGATIVE_INFINITY }).element;
    }

    // --- LEJÁTSZÁS KÖZPONTI FÜGGVÉNYE ---
    function playSongByFile(file, isHistoryBack = false) {
        const el = document.querySelector(`.song-item[data-file="${file}"]`);
        if (!el) return;

        if (!isHistoryBack) playHistory.push(file);

        document.querySelectorAll('.song-item.active').forEach(e => e.classList.remove('active'));
        document.querySelectorAll(`.song-item[data-file="${file}"]`).forEach(e => e.classList.add('active'));
        
        audioPlayer.src = file;
        npTitle.textContent = el.getAttribute('data-title');
        npArtist.textContent = el.getAttribute('data-artist');
        npCover.src = '?cover=' + encodeURIComponent(file);
        
        playAudio();
        checkMarquee(); 
    }

    function setupPlayListeners() {
        document.querySelectorAll('.song-item').forEach(item => {
            item.removeEventListener('click', handleSongClick); 
            item.addEventListener('click', handleSongClick);
        });
        
        document.querySelectorAll('.btn-add-queue').forEach(btn => {
            btn.removeEventListener('click', handleQueueAdd);
            btn.addEventListener('click', handleQueueAdd);
        });
    }

    function handleSongClick(e) {
        if (!e.target.closest('.btn-add-queue')) {
            const file = this.getAttribute('data-file');
            playSongByFile(file);
        }
    }

    function handleQueueAdd(e) {
        e.stopPropagation(); 
        const file = this.getAttribute('data-file');
        const title = this.closest('.song-item').getAttribute('data-title');
        const artist = this.closest('.song-item').getAttribute('data-artist');
        
        playQueue.push({ file, title, artist });
        renderQueue(); 
        
        const origHtml = this.innerHTML;
        this.innerHTML = svgCheck;
        this.style.color = 'var(--accent-color)';
        setTimeout(() => { 
            this.innerHTML = origHtml; 
            this.style.color = '';
        }, 1000);
    }

    // --- VEZÉRLÉS ---
    function playAudio() {
        audioPlayer.play();
        isPlaying = true;
        btnPlayPause.innerHTML = svgPause;
    }
    
    function pauseAudio() {
        audioPlayer.pause();
        isPlaying = false;
        btnPlayPause.innerHTML = svgPlay;
    }

    btnPlayPause.addEventListener('click', () => {
        if (!audioPlayer.src || audioPlayer.src.endsWith('index.php')) return; 
        if (isPlaying) pauseAudio(); else playAudio();
    });

    btnShuffle.addEventListener('click', () => {
        isShuffle = !isShuffle;
        btnShuffle.classList.toggle('active-mode', isShuffle);
    });

    function playNext() {
        if (playQueue.length > 0) {
            const nextSong = playQueue.shift();
            renderQueue(); 
            playSongByFile(nextSong.file);
            return;
        }

        if (isShuffle) {
            const activeContainer = document.getElementById('viewListContainer').style.display !== 'none' ? document.getElementById('viewListContainer') : document.getElementById('viewAlbumContainer');
            const visibleSongs = Array.from(activeContainer.querySelectorAll('.song-item')).filter(el => el.style.display !== 'none');
            if (visibleSongs.length > 0) {
                const randomIndex = Math.floor(Math.random() * visibleSongs.length);
                const file = visibleSongs[randomIndex].getAttribute('data-file');
                playSongByFile(file);
            }
            return;
        }

        const activeContainer = document.getElementById('viewListContainer').style.display !== 'none' ? document.getElementById('viewListContainer') : document.getElementById('viewAlbumContainer');
        const active = activeContainer.querySelector('.song-item.active');
        if (active) {
            let next = active.nextElementSibling;
            while (next && next.style.display === 'none') { next = next.nextElementSibling; } 
            
            if (!next && activeContainer.id === 'viewAlbumContainer') {
                const nextAlbum = active.closest('.album-section').nextElementSibling;
                if (nextAlbum) next = nextAlbum.querySelector('.song-item');
            }
            if (next) playSongByFile(next.getAttribute('data-file'));
        }
    }

    function playPrev() {
        if (audioPlayer.currentTime > 3) {
            audioPlayer.currentTime = 0;
            return;
        }
        
        if (playHistory.length > 1) {
            playHistory.pop(); 
            const prevFile = playHistory.pop(); 
            playSongByFile(prevFile, true); 
            return;
        }

        const activeContainer = document.getElementById('viewListContainer').style.display !== 'none' ? document.getElementById('viewListContainer') : document.getElementById('viewAlbumContainer');
        const active = activeContainer.querySelector('.song-item.active');
        if (active) {
            let prev = active.previousElementSibling;
            while (prev && prev.style.display === 'none') { prev = prev.previousElementSibling; }
            
            if (!prev && activeContainer.id === 'viewAlbumContainer') {
                const prevAlbum = active.closest('.album-section').previousElementSibling;
                if (prevAlbum) {
                    const songs = prevAlbum.querySelectorAll('.song-item');
                    prev = songs[songs.length - 1]; 
                }
            }
            if (prev) playSongByFile(prev.getAttribute('data-file'));
        }
    }

    btnNext.addEventListener('click', playNext);
    btnPrev.addEventListener('click', playPrev);
    audioPlayer.addEventListener('ended', playNext);
    
    // --- CSÚSZKÁK ---
    function updateSliderFill(slider) {
        const percent = ((slider.value - slider.min) / (slider.max - slider.min)) * 100;
        slider.style.setProperty('--val', `${percent}%`);
    }

    function formatTime(seconds) {
        if (isNaN(seconds)) return "0:00";
        const min = Math.floor(seconds / 60);
        const sec = Math.floor(seconds % 60);
        return `${min}:${sec < 10 ? '0' : ''}${sec}`;
    }

    audioPlayer.addEventListener('timeupdate', () => {
        if (!isSeeking && audioPlayer.duration) {
            const current = audioPlayer.currentTime;
            const duration = audioPlayer.duration;
            currentTimeEl.textContent = formatTime(current);
            totalTimeEl.textContent = formatTime(duration);
            progressBar.value = (current / duration) * 100;
            updateSliderFill(progressBar);
        }
    });

    progressBar.addEventListener('input', () => {
        isSeeking = true;
        updateSliderFill(progressBar);
        if (audioPlayer.duration) {
            currentTimeEl.textContent = formatTime((progressBar.value / 100) * audioPlayer.duration);
        }
    });

    progressBar.addEventListener('change', () => {
        if (audioPlayer.duration) {
            audioPlayer.currentTime = (progressBar.value / 100) * audioPlayer.duration;
        }
        isSeeking = false;
    });

    audioPlayer.volume = 1.0; 
    updateSliderFill(volumeBar);
    
    volumeBar.addEventListener('input', (e) => {
        updateSliderFill(e.target);
        const val = e.target.value / 100;
        audioPlayer.volume = val;
        
        if (val == 0) {
            volumeIcon.innerHTML = svgVolMute;
            isMuted = true;
        } else {
            if (val < 0.5) volumeIcon.innerHTML = svgVolMid;
            else volumeIcon.innerHTML = svgVolHigh;
            isMuted = false;
            previousVolume = e.target.value; // Megjegyezzük az utolsó hangerőt
        }
    });

    // Némítás gombra kattintás egeres vezérléshez is
    volumeIcon.addEventListener('click', toggleMute);
    volumeIcon.style.cursor = 'pointer';

    // --- NÉZET ÉS KERESÉS ---
    const btnViewAlbum = document.getElementById('btnViewAlbum');
    const btnViewList = document.getElementById('btnViewList');
    const viewAlbumContainer = document.getElementById('viewAlbumContainer');
    const viewListContainer = document.getElementById('viewListContainer');

    function applySearch() {
        const term = searchInput.value.toLowerCase();
        if (viewAlbumContainer.style.display !== 'none') {
            document.querySelectorAll('#viewAlbumContainer .album-section').forEach(album => {
                let hasVisible = false;
                album.querySelectorAll('.song-item').forEach(song => {
                    const match = song.getAttribute('data-search-text').includes(term) || album.getAttribute('data-search-text').includes(term);
                    song.style.display = match ? 'flex' : 'none';
                    if (match) hasVisible = true;
                });
                album.style.display = hasVisible ? 'block' : 'none';
            });
        }
        if (viewListContainer.style.display !== 'none') {
            document.querySelectorAll('#viewListContainer .song-item').forEach(song => {
                const match = song.getAttribute('data-search-text').includes(term);
                song.style.display = match ? 'flex' : 'none';
            });
        }
    }

    searchInput.addEventListener('input', applySearch);

    btnViewAlbum.addEventListener('click', () => {
        btnViewAlbum.classList.add('active'); btnViewList.classList.remove('active');
        viewAlbumContainer.style.display = 'block'; viewListContainer.style.display = 'none';
        applySearch();
    });

    btnViewList.addEventListener('click', () => {
        btnViewList.classList.add('active'); btnViewAlbum.classList.remove('active');
        viewAlbumContainer.style.display = 'none'; viewListContainer.style.display = 'block';
        applySearch(); 
    });

    setupPlayListeners();
</script>

</body>
</html>