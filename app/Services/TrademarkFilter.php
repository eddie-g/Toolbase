<?php

namespace App\Services;

/**
 * TrademarkFilter
 *
 * Scans a user-supplied prompt for references to well-known trademarked or
 * copyright-protected fictional IP (characters, franchises, branded toys, etc.).
 *
 * This does NOT attempt to be an exhaustive legal list – it catches the most
 * common cases that could expose the platform to trademark / copyright liability
 * when generating images or branding assets.
 *
 * Usage:
 *   $result = TrademarkFilter::check($promptText);
 *   if (!$result['safe']) {
 *       return response()->json(['error' => $result['message']], 422);
 *   }
 */
class TrademarkFilter
{
    /**
     * Each entry is either:
     *   - A plain string  → matched as a case-insensitive whole word / phrase
     *   - A regex string starting with '~' → used as-is (without the leading ~)
     *
     * Group similar IP together with a comment for readability.
     *
     * @var list<string>
     */
    private static array $terms = [

        // ── Pokémon ───────────────────────────────────────────────────────────
        'pokemon', 'pokémon', 'pokedex', 'pokédex',
        'pikachu', 'charizard', 'mewtwo', 'eevee', 'gengar', 'bulbasaur',
        'squirtle', 'jigglypuff', 'snorlax', 'gyarados', 'lucario', 'greninja',
        'rayquaza', 'arceus', 'mew', 'raichu', 'blastoise', 'venusaur',
        'charmander', 'clefairy', 'psyduck', 'slowpoke', 'magikarp',
        'umbreon', 'espeon', 'sylveon', 'dragonite', 'metagross',

        // ── LEGO ─────────────────────────────────────────────────────────────
        'lego', 'bionicle', 'ninjago', 'nexo knights', 'lego technic',
        'lego city', 'lego friends', 'lego mindstorms',

        // ── Harry Potter / Wizarding World ───────────────────────────────────
        'harry potter', 'hogwarts', 'hermione', 'dumbledore', 'voldemort',
        'quidditch', 'diagon alley', 'gryffindor', 'slytherin', 'hufflepuff',
        'ravenclaw', 'wizard world', 'wizarding world', 'dementor', 'horcrux',
        'butterbeer', 'triwizard', 'death eater',

        // ── Warhammer ─────────────────────────────────────────────────────────
        'warhammer', 'warhammer 40k', 'warhammer 40,000', 'space marine',
        'adeptus astartes', 'chaos space marine', 'necron', 'tyranid',
        'eldar', 'tau empire', 'ork warhammer', 'imperial guard', 'inquisitor',
        'age of sigmar', 'stormcast eternal', 'nurgle', 'khorne', 'tzeentch',
        'slaanesh',

        // ── Dungeons & Dragons / D&D ─────────────────────────────────────────
        'dungeons and dragons', 'dungeons & dragons', 'd&d',
        'beholder', 'mind flayer', 'illithid', 'owlbear', 'displacer beast',
        'drow elf',

        // ── Magic: The Gathering ──────────────────────────────────────────────
        'magic the gathering', 'magic: the gathering', 'planeswalker',

        // ── Yu-Gi-Oh ──────────────────────────────────────────────────────────
        'yu-gi-oh', 'yugioh', 'yu gi oh', 'duel monsters', 'blue-eyes white dragon',
        'dark magician', 'exodia',

        // ── Star Wars ─────────────────────────────────────────────────────────
        'star wars', 'darth vader', 'darth maul', 'luke skywalker', 'han solo',
        'princess leia', 'obi-wan', 'obi wan', 'yoda', 'chewbacca', 'r2-d2',
        'c-3po', 'stormtrooper', 'lightsaber', 'millennium falcon', 'death star',
        'jedi', 'sith', 'the force', 'mandalorian', 'baby yoda', 'grogu',
        'boba fett', 'jabba the hutt', 'tie fighter', 'x-wing', 'at-at',
        'kylo ren', 'rey skywalker', 'emperor palpatine', 'clone trooper',

        // ── Marvel ────────────────────────────────────────────────────────────
        'spider-man', 'spiderman', 'iron man', 'captain america', 'thor',
        'hulk', 'black widow', 'hawkeye', 'ant-man', 'antman', 'black panther',
        'doctor strange', 'dr strange', 'scarlet witch', 'vision', 'loki',
        'deadpool', 'wolverine', 'cyclops', 'magneto', 'professor x',
        'avengers', 'x-men', 'guardians of the galaxy', 'thanos', 'infinity gauntlet',
        'nick fury', 'shield helicarrier', 'vibranium', 'mjolnir', 'asgard',

        // ── DC Comics ─────────────────────────────────────────────────────────
        'batman', 'bruce wayne', 'gotham', 'joker', 'harley quinn',
        'superman', 'clark kent', 'wonder woman', 'aquaman', 'the flash',
        'green lantern', 'cyborg', 'lex luthor', 'catwoman', 'bane', 'robin',
        'nightwing', 'batmobile', 'kryptonite', 'arkham', 'justice league',

        // ── Lord of the Rings / Tolkien ───────────────────────────────────────
        'lord of the rings', 'lotr', 'the hobbit', 'gandalf', 'frodo baggins',
        'samwise', 'aragorn', 'legolas', 'gimli', 'sauron', 'one ring',
        'mordor', 'shire', 'rivendell', 'isengard', 'ent', 'nazgul',
        'ring wraith', 'balrog', 'gollum', 'bilbo',

        // ── Game of Thrones / House of the Dragon ─────────────────────────────
        'game of thrones', 'house of the dragon', 'westeros',
        'khaleesi', 'daenerys', 'targaryen', 'lannister', 'stark', 'baratheon',
        'dragon glass', 'iron throne', 'winterfell', 'king\'s landing',

        // ── Nintendo ──────────────────────────────────────────────────────────
        'mario', 'luigi', 'princess peach', 'bowser', 'toad', 'yoshi',
        'wario', 'waluigi', 'donkey kong', 'link', 'zelda', 'ganon', 'ganondorf',
        'hyrule', 'triforce', 'kirby', 'metroid', 'samus aran', 'fox mccloud',
        'star fox', 'pikmin', 'captain toad', 'fire emblem',

        // ── Sonic the Hedgehog (Sega) ────────────────────────────────────────
        'sonic the hedgehog', 'miles tails', 'knuckles', 'shadow the hedgehog',
        'dr eggman', 'doctor robotnik', 'amy rose hedgehog',

        // ── Minecraft (Microsoft / Mojang) ───────────────────────────────────
        'minecraft', 'creeper minecraft', 'steve minecraft', 'enderman',
        'herobrine',

        // ── Fortnite / Epic ──────────────────────────────────────────────────
        'fortnite',

        // ── Disney / Pixar characters ────────────────────────────────────────
        'mickey mouse', 'minnie mouse', 'donald duck', 'goofy disney',
        'tinker bell', 'peter pan disney', 'simba', 'nemo', 'dory finding',
        'elsa frozen', 'anna frozen', 'olaf frozen', 'moana disney',
        'buzz lightyear', 'woody toy story', 'forky', 'wall-e', 'eve walle',
        'lightning mcqueen',

        // ── Anime / Manga ─────────────────────────────────────────────────────
        'naruto uzumaki', 'sasuke uchiha', 'kakashi', 'goku', 'vegeta',
        'dragon ball', 'dragonball', 'one piece', 'monkey d luffy', 'zoro',
        'sailor moon', 'fullmetal alchemist', 'edward elric', 'attack on titan',
        'eren yeager', 'levi ackerman', 'demon slayer', 'tanjiro',
        'my hero academia', 'deku', 'all might', 'jujutsu kaisen', 'gojo',
        'bleach anime', 'ichigo kurosaki',

        // ── Transformers ─────────────────────────────────────────────────────
        'transformers', 'optimus prime', 'bumblebee transformer', 'megatron',
        'autobots', 'decepticons',

        // ── My Little Pony ────────────────────────────────────────────────────
        'my little pony', 'twilight sparkle', 'rainbow dash', 'pinkie pie pony',
        'fluttershy', 'applejack pony', 'rarity pony', 'brony',

        // ── Barbie (Mattel) ───────────────────────────────────────────────────
        'barbie doll', 'ken doll',

        // ── SpongeBob ─────────────────────────────────────────────────────────
        'spongebob', 'sponge bob', 'patrick star', 'squidward', 'sandy cheeks',
        'mr krabs', 'bikini bottom',

        // ── Halo (Microsoft / 343i) ───────────────────────────────────────────
        'master chief', 'halo game', 'cortana halo', 'arbiter halo',

        // ── Call of Duty (Activision) ─────────────────────────────────────────
        'call of duty', 'warzone', 'captain price', 'ghost cod',

        // ── God of War (Sony) ─────────────────────────────────────────────────
        'kratos god of war', 'atreus god of war',

        // ── Overwatch (Blizzard) ──────────────────────────────────────────────
        'overwatch', 'tracer overwatch', 'mercy overwatch', 'reinhardt overwatch',

        // ── World of Warcraft / Diablo / Blizzard ─────────────────────────────
        'world of warcraft', 'wow warcraft', 'diablo blizzard', 'thrall warcraft',
        'arthas', 'lich king',

        // ── Pac-Man (Bandai Namco) ────────────────────────────────────────────
        'pac-man', 'pacman', 'ms pac-man',

        // ── League of Legends / Riot ──────────────────────────────────────────
        'league of legends', 'valorant',

        // ── Among Us (Innersloth) ────────────────────────────────────────────
        'among us game',

        // ── Intellectual property catch-alls ─────────────────────────────────
        // Regex patterns (prefix with ~) for things hard to enumerate
        '~/\bpokemon\s+\w+\b/i',  // "pokemon [character]" patterns
    ];

    /**
     * Check a prompt string for trademarked / copyright-protected IP references.
     *
     * @param  string  $text  The user-supplied prompt to scan.
     * @return array{safe: bool, match: string|null, message: string}
     */
    public static function check(string $text): array
    {
        $normalised = mb_strtolower($text);

        foreach (self::$terms as $term) {
            if (str_starts_with($term, '~')) {
                // Raw regex
                $pattern = substr($term, 1);
                if (preg_match($pattern, $text)) {
                    $display = preg_replace('/[~\\\\^$.|?*+(){}\\[\\]\/]/u', '', $pattern);
                    return self::blocked(trim($display));
                }
            } else {
                // Whole-word / whole-phrase match (word boundary on each side)
                $escaped  = preg_quote($term, '/');
                $pattern  = '/(?<![a-z0-9])' . $escaped . '(?![a-z0-9])/iu';
                if (preg_match($pattern, $normalised)) {
                    return self::blocked($term);
                }
            }
        }

        return ['safe' => true, 'match' => null, 'message' => ''];
    }

    /** Build the blocked result array. */
    private static function blocked(string $match): array
    {
        $display = ucwords($match);
        return [
            'safe'    => false,
            'match'   => $match,
            'message' => "Your prompt references \"{$display}\", which is a trademarked or copyright-protected brand / character. "
                       . 'Please rephrase your prompt without using protected IP names to avoid trademark or copyright infringement.',
        ];
    }
}
