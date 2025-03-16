@extends('layouts.admin')

@section("content")
    <div class="app-content-header">
        <div class="container-fluid">
            <div class="row">
                <div class="col-sm-6"><h3 class="mb-0">Accounts</h3></div>
            </div>
        </div>
    </div>

    <div class="app-content">
        <div class="container-fluid">

            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">All Accounts</h3>
                </div>
                <div class="card-body">
                    <div id="all_accounts">
                        <div class="row">
                            <div class="col-sm-12 col-md-6"></div>
                            <div class="col-sm-12 col-md-6"></div>
                        </div>
                        <div class="row">
                            <div class="col-sm-12">
                                <div class="overflow-x-auto">
                                    <table id="account_table" class="table table-bordered table-hover">
                                        <thead>
                                        <tr>
                                            <th>Nation ID</th>
                                            <th>Owner</th>
                                            <th>Name</th>
                                            <th>Money</th>
                                            <th>Coal</th>
                                            <th>Oil</th>
                                            <th>Uranium</th>
                                            <th>Lead</th>
                                            <th>Iron</th>
                                            <th>Bauxite</th>
                                            <th>Gas</th>
                                            <th>Munitions</th>
                                            <th>Steel</th>
                                            <th>Aluminum</th>
                                            <th>Food</th>
                                        </tr>
                                        </thead>
                                        <tbody>
                                        @foreach ($accounts as $acc)
                                            <tr>
                                                <td><a href="https://politicsandwar.com/nation/id={{ $acc->nation_id }}"
                                                       target="_blank">{{ $acc->nation_id }}</a></td>
                                                <td><a href="#">{{ $acc->user->name ?? "Deleted Account" }}</a></td>
                                                <td><a href="{{ route("admin.accounts.view", $acc->id) }}"
                                                       target="_blank">{{ $acc->name }}</a></td>
                                                <td>${{ number_format($acc->money, 2) }}</td>
                                                <td>{{ number_format($acc->coal, 2) }}</td>
                                                <td>{{ number_format($acc->oil, 2) }}</td>
                                                <td>{{ number_format($acc->uranium, 2) }}</td>
                                                <td>{{ number_format($acc->lead, 2) }}</td>
                                                <td>{{ number_format($acc->iron, 2) }}</td>
                                                <td>{{ number_format($acc->bauxite, 2) }}</td>
                                                <td>{{ number_format($acc->gas, 2) }}</td>
                                                <td>{{ number_format($acc->munitions, 2) }}</td>
                                                <td>{{ number_format($acc->steel, 2) }}</td>
                                                <td>{{ number_format($acc->aluminum, 2) }}</td>
                                                <td>{{ number_format($acc->food, 2) }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                @endsection

                @section("scripts")
                    <script>
                        $(document).ready(function () {
                            $('#account_table').DataTable();
                        });
                    </script>
@endsection
