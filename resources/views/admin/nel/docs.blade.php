@extends('layouts.admin')

@section('title', 'NEL Documentation')

@section('content')
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row align-items-center">
                <div class="col-12 col-lg-8">
                    <h3 class="mb-1">Nexus Expression Language (NEL)</h3>
                    <p class="text-secondary mb-0">
                        Define safe, predictable expressions for audits, grants, and MMR checks using dot-notation like
                        <code>nation.score</code> or <code>nation.military.soldiers</code>.
                    </p>
                </div>
                <div class="col-12 col-lg-4 mt-3 mt-lg-0 text-lg-end">
                    <span class="badge bg-primary-subtle text-primary-emphasis">
                        <i class="bi bi-shield-lock me-1"></i> Sandboxed &amp; read-only
                    </span>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">
                    Overview
                </div>
                <div class="card-body">
                    <ul class="list-unstyled mb-0">
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i> Dot-notation walks the
                            variable tree you provide (e.g., <code>nation.military.soldiers</code>).
                        </li>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i> Supports numbers, strings,
                            booleans, null, arithmetic, comparisons, and boolean logic.
                        </li>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i> Function hooks are supported
                            via helpers (future-ready; no domain helpers ship yet).
                        </li>
                        <li class="mb-2"><i class="bi bi-check-circle-fill text-success me-1"></i> Evaluation is pure: no DB,
                            filesystem, or network access.
                        </li>
                        <li class="mb-0"><i class="bi bi-check-circle-fill text-success me-1"></i> Backed by the NEL core in
                            <code>App\Nel</code> and adapter profiles (e.g., nation → <code>nation.*</code>).
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">
                    Syntax Reference
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-secondary text-uppercase small">Variables &amp; paths</div>
                        <p class="mb-1"><code>nation.score</code>, <code>nation.military.soldiers</code>,
                            <code>city.infrastructure</code>, <code>city.land</code></p>
                    </div>
                    <div class="mb-3">
                        <div class="fw-semibold text-secondary text-uppercase small">Literals</div>
                        <p class="mb-1">Numbers (<code>1200</code>, <code>3.14</code>), strings (<code>"text"</code> or
                            <code>'text'</code>), booleans (<code>true</code>, <code>false</code>), null (<code>null</code>).
                        </p>
                    </div>
                    <div class="mb-0">
                        <div class="fw-semibold text-secondary text-uppercase small">Operators</div>
                        <p class="mb-1">Comparison: <code>==</code>, <code>!=</code>, <code>&lt;</code>,
                            <code>&lt;=</code>, <code>&gt;</code>, <code>&gt;=</code></p>
                        <p class="mb-1">Boolean: <code>&amp;&amp;</code>, <code>||</code>, <code>!</code></p>
                        <p class="mb-0">Arithmetic: <code>+</code>, <code>-</code>, <code>*</code>, <code>/</code>,
                            <code>%</code>, parentheses <code>( )</code></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">
                    Operator Precedence
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0 align-middle">
                            <thead>
                            <tr>
                                <th scope="col" class="text-secondary text-uppercase small">Level</th>
                                <th scope="col" class="text-secondary text-uppercase small">Operators</th>
                            </tr>
                            </thead>
                            <tbody>
                            <tr>
                                <td>1 (highest)</td>
                                <td><code>( )</code></td>
                            </tr>
                            <tr>
                                <td>2</td>
                                <td><code>!</code>, unary <code>-</code></td>
                            </tr>
                            <tr>
                                <td>3</td>
                                <td><code>*</code>, <code>/</code>, <code>%</code></td>
                            </tr>
                            <tr>
                                <td>4</td>
                                <td><code>+</code>, <code>-</code></td>
                            </tr>
                            <tr>
                                <td>5</td>
                                <td><code>&lt;</code>, <code>&lt;=</code>, <code>&gt;</code>, <code>&gt;=</code>,
                                    <code>==</code>, <code>!=</code></td>
                            </tr>
                            <tr>
                                <td>6</td>
                                <td><code>&amp;&amp;</code></td>
                            </tr>
                            <tr>
                                <td>7 (lowest)</td>
                                <td><code>||</code></td>
                            </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="text-secondary small mb-0 mt-2">All binary operators are left-associative. Parentheses win.</p>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header fw-semibold">
                    Examples
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="fw-semibold text-secondary text-uppercase small">Readiness checks</div>
                        <pre class="bg-body-secondary p-3 rounded mb-0"><code>nation.score &gt; 500 &amp;&amp; nation.military.soldiers &gt; 10000
city.infrastructure % 50 != 0 || city.land % 50 != 0
!(nation.score &gt;= 1000)</code></pre>
                    </div>
                    <div>
                        <div class="fw-semibold text-secondary text-uppercase small">Future helper shape</div>
                        <pre class="bg-body-secondary p-3 rounded mb-0"><code>hasProject("Nuclear Research")
double(nation.score)</code></pre>
                        <p class="text-secondary small mb-0 mt-1">Helpers are registered server-side; unknown helpers throw
                            an error.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-1">
        <div class="col-12">
            <div class="card shadow-sm">
                <div class="card-header fw-semibold">
                    Security Notes
                </div>
                <div class="card-body">
                    <ul class="mb-0">
                        <li>Expressions cannot run arbitrary PHP—no <code>eval</code>, reflection tricks, or dynamic code.</li>
                        <li>Evaluation is pure and read-only: no database, filesystem, or network calls.</li>
                        <li>NEL only sees data mapped into variables (e.g., via a profile that loads <code>nation.*</code>).</li>
                        <li>Unknown variables or helpers raise clear errors to avoid silent fallbacks.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
@endsection
